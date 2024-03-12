<?php
$config = require __DIR__ . '/config.php';

extract($config);

//connect to database
try {
    $db = new PDO("mysql:host=$dbhost;dbname=$dbname", $dbuser, $dbpass);
} catch (PDOException $ex) {
    die($ex->getMessage());
}

$sth = $db->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema = ?", [
    PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY,
]);
$sth->execute([$dbname]);

$excluded_types = [
    'timestamp',
    'datetime',
];

$serialised_table_suffixes = [
    'options',
];

function ends_with_any($haystack, $needles) {
    foreach ($needles as $needle) {
        if (substr($haystack, -strlen($needle)) === $needle) {
            return true;
        }
    }

    return false;
}

function unserialise($serialised_string) {
    if (!is_serialized($serialised_string)) {
        return false;
    }

    $serialised_string = trim($serialised_string);

    return @unserialize($serialised_string);
}

function fr_str_replace($from, $to, $data, $case_insensitive = false) {
    if ($case_insensitive) {
        $data = str_ireplace($from, $to, $data);
    } else {
        $data = str_replace($from, $to, $data);
    }

    return $data;
}

function recursive_unserialize_replace($from, $to, $data, $serialised = false, $case_insensitive = false) {
    try {
        if (is_string($data) && !is_serialized_string($data) && ($unserialized = unserialise($data)) !== false) {
            $data = recursive_unserialize_replace($from, $to, $unserialized, true, $case_insensitive);
        } else if (is_array($data)) {
            $_tmp = [];
            foreach ($data as $key => $value) {
                $_tmp[$key] = recursive_unserialize_replace($from, $to, $value, false, $case_insensitive);
            }

            $data = $_tmp;
            unset($_tmp);
        } else if (is_object($data)) {
            if ('__PHP_Incomplete_Class' !== get_class($data)) {
                $_tmp = $data;
                $props = get_object_vars($data);
                foreach ($props as $key => $value) {
                    // Integer properties are crazy and the best thing we can do is to just ignore them.
                    // see http://stackoverflow.com/a/10333200
                    if (is_int($key)) {
                        continue;
                    }

                    // Skip any representation of a protected property
                    // https://github.com/deliciousbrains/better-search-replace/issues/71#issuecomment-1369195244
                    if (is_string($key) && 1 === preg_match("/^([\\][0])?/im", $key)) {
                        continue;
                    }

                    $_tmp->$key = recursive_unserialize_replace($from, $to, $value, false, $case_insensitive);
                }

                $data = $_tmp;
                unset($_tmp);
            }
        } else if (is_serialized_string($data)) {
            $unserialized = unserialise($data);

            if ($unserialized !== false) {
                $data = recursive_unserialize_replace($from, $to, $unserialized, true, $case_insensitive);
            }
        } else {
            if (is_string($data)) {
                $data = fr_str_replace($from, $to, $data, $case_insensitive);
            }
        }

        if ($serialised) {
            return serialize($data);
        }
    } catch(Exception $error) {

    }

    return $data;
}

function get_columns(PDO $db, $table) {
    $primary_key = null;
    $columns = [];
    $sth = $db->prepare('DESCRIBE ' . $table);
    $fields = $sth->fetchAll(PDO::FETCH_OBJ);

    if (is_array($fields)) {
        foreach ($fields as $column) {
            $columns[] = $column->Field;
            if ($column->Key == 'PRI') {
                $primary_key = $column->Field;
            }
        }
    }

    return [$primary_key, $columns];
}

function find_and_replace_serialised_tables(PDO $db, $replace_array, $table_name) {
    $sth = $db->prepare('SELECT * FROM `' . $table_name . '`');
    [$primary_key, $columns] = get_columns($db, $table_name);

    while ($row = $sth->fetch()) {
        $update_sql = [];
        $update_params = [];
        $where_sql = [];
        $where_params = [];

        foreach ($replace_array as $find => $replace) {
            foreach ($columns as $column) {
                $data_to_fix = $row[$column];

                if ($column == $primary_key) {
					$where_sql[] = $column . ' = ?';
                    $where_params[] = $data_to_fix;
					continue;
				}

                $edited_data = recursive_unserialize_replace($find, $replace, $data_to_fix);

                if ($edited_data == $data_to_fix) {
                    continue;
                }

                $update_sql[] = $column . ' = ?';
                $update_params[] = $edited_data;
            }
        }

        $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $update_sql) . ' WHERE ' . implode(' AND ', array_filter($where_sql));
        $params = array_merge($update_params, $where_params);
        $db->prepare($sql)->execute($params);
    }
}

$db->beginTransaction();
while ($row = $sth->fetch()) {
    $table_name = $row['TABLE_NAME'] ?? $row['table_name'];

    if (ends_with_any($table_name, $serialised_table_suffixes)) {
        find_and_replace_serialised_tables($db, $replace_array, $table_name);
        continue;
    }

    $sth2 = $db->prepare("SHOW COLUMNS FROM `{$table_name}`");
    $sth2->execute();
    $columns = [];

    while ($column_row = $sth2->fetch()) {
        if (in_array($column_row['Type'], $excluded_types)) {
            continue;
        }

        $columns[] = $column_row['Field'];
    }

    $replace_sql = "UPDATE `{$table_name}` SET";
    $replace_params = [];
    $replace_columns_sql = [];
    foreach ($replace_array as $find => $replace) {
        foreach ($columns as $column) {
            $replace_columns_sql[] = "`{$column}` = REPLACE(`{$column}`, ?, ?)";
            $replace_params[] = $find;
            $replace_params[] = $replace;
        }
    }
    $replace_sql .= ' ' . implode(', ', $replace_columns_sql);

    print("Replacing table {$table_name}\n");
    $replace_sth = $db->prepare($replace_sql);
    try {
        $replace_sth->execute($replace_params);
    } catch (Throwable $ex) {
        print("Error: {$ex->getMessage()}\n");
    }
}
$db->commit();
