<?php

$create_table = false; // false to skip create table statements
$insert_into = true; // false to skip insert statements

$line_limit = null; // null for no limit
$merge_inserts = true; // true to merge two inserts into one statement (to speedup import)

$verbose_comments = false;
$verbose_set = false;
$verbose_ignored = false;
$verbose_column_definitions = false;
$verbose_insert_into_keyword = false; // false to skip INTO keyword
$verbose_insert_values_keyword = false; // false to save 1 byte per insert statement
$explicit_column_list = false;

$storage_engines = [    // storage engines to use for CREATE TABLE statements, actually unused in the code for time being
    'InnoDB',
    'MyISAM',
    'MEMORY',
    'CSV',
];

$default_table_engine = 'MEMORY';

// if no input file path is provided then exit
if (!isset($argv[1])) {
    echo "Please provide input file path as first argument\n";
    exit;
}

$ingore_lines_starting_with = array(
    "SELECT",
    "ALTER",
    "COMMENT",
    "CREATE TRIGGER",
    "CREATE INDEX",
    "CREATE UNIQUE INDEX",
    "CREATE SEQUENCE",
    "GRANT",
);

// create regex pattern to match lines starting with above strings
$ingore_lines_starting_with_regex = "/^(" . implode("|", $ingore_lines_starting_with) . ")/";

// read input file path from command line
$input_file = $argv[1];

// read input file line by line
// files will be very large so we can't read whole file in memory
$handle = fopen($input_file, "r");

// open output file
//$output_file = fopen("output.sql", "w");

// read file line by line till end of file
// ingore blank lines
// echo lines starting with -- as comments
// parse lines starting with SET as key = value and save to array $set
// ignore lines starting with ALTER and COMMENT

$set = array();
$state = null;
$terminator = null;
$target_table = null;
$first_line = true;
$columns_list = [];
$sql = '';

$line_count = 0;

if ($verbose_insert_into_keyword) {
    $into = 'INTO ';
} else {
    $into = '';
}

if ($verbose_insert_values_keyword) {
    $values = 'VALUES ';
} else {
    $values = 'VALUE ';
}

?>
SET NAMES utf8;
SET CHARACTER SET utf8;
<?php

// start loop
while (($line = fgets($handle)) !== false) {
    if ($line_limit && $line_count++ > $line_limit) {
        echo "-- LIMIT REACHED\n";
        break;
    }

    if ($state == 'CREATE TABLE' && $terminator) {
        if (str_starts_with($line, $terminator)) {
            //echo "\n$terminator\n";
            if ($create_table) {
                echo ") ENGINE=$default_table_engine DEFAULT CHARACTER SET = utf8;\n";
                echo "-- END OF TABLE DEFINITION\n\n";
            }
            $state = null;
            $terminator = null;
            continue;
        }

        if (!$create_table) {
            continue;
        }

        // remove trailing comma
        $line = rtrim($line, ",\n");

        // remove leading and trailing spaces
        $line = trim($line);

        if ($verbose_column_definitions) {
            echo "# COLUMN DEFINITION: $line";
        }

        if (str_starts_with($line, 'CONSTRAINT')) {
            continue;
        }

        $mysql_line = strtr(strtolower($line), [
            'boolean' => 'char(1)',
            'character' => 'char',
            'timestamp without time zone' => 'timestamp',
            'timestamp with time zone' => 'timestamp',
            'double precision' => 'double',
            'real' => 'float',
            'bytea' => 'blob',
            '::bpchar' => '',
            '::character varying' => '',
        ]);

        if ($first_line) {
            $first_line = false;
        } else {
            echo ",";
        }

        echo "\t$mysql_line\n";

        continue;
    }

    if ($state == 'COPY' && $terminator) {
        if (str_starts_with($line, $terminator)) {

            if ($first_line === false) {
                echo ");\n";
            } 

            $state = null;
            $terminator = null;
            continue;
        }

        if (!$insert_into) {
            continue;
        }
        
        // convert line to array as CSV delimited by tab
        $parts = explode("\t", trim($line));

        // convert to MySQL insert statement

        if ($first_line) {
            if ($explicit_column_list) {
                $sql = "INSERT $into$target_table $columns_list $values(";
            } else {
                $sql = "INSERT $into$target_table $values(";
            }
        } else {
            $sql = '';
        }

        for ($i = 0; $i < count($parts); $i++) {
            $cell = $parts[$i];
            if ($cell == "\\N") {
                $cell = "NULL";
            } elseif (preg_match('/(\d\d\d\d-\d\d-\d\d \d\d:\d\d:\d\d)/', $cell, $matches)) {
                $cell = "'" . $matches[1] . "'";
            } else {
                $cell = strtr($cell, [
                    "'" => "\\'",
                    "\n" => "\\n",
                    "\r" => "\\r",
                    "\t" => "\\t",
                    '\\' => '\\\\',
                ]);
                $cell = "'$cell'";
            }
            if ($i > 0) {
                $sql .= ',';
            }
            $sql .= $cell;
        }

        if ($merge_inserts === false) {
            $first_line = false;
        }

        if ($first_line) {
            $sql .= "),(";
        } else {
            $sql .= ");\n";
        }
        $first_line = !$first_line;
        echo $sql;
        continue;
    }

    // ignore blank lines
    if (trim($line) == "") {
        continue;
    }

    // echo lines starting with -- as comments
    if (str_starts_with($line, "--")) {
        if ($verbose_comments) {
            echo $line;
        }
        continue;
    }

    // parse lines starting with SET as key = value and save to array $set
    if (substr($line, 0, 3) == "SET") {
        $rest = substr(trim($line, ';'), 4);
        $parts = explode("=", $rest);
        $key = trim($parts[0]);
        $value = trim($parts[1]);
        if ($verbose_set) {
            echo $key . " = " . $value . "\n";
        }
        continue;
    }

    // ignore lines starting with ALTER and COMMENT
    if (
        str_starts_with($line, " ") 
        || preg_match($ingore_lines_starting_with_regex, $line)
        ) {
        if ($verbose_ignored) {
            echo "IGNORED: $line";
        }
        continue;
    }

    if (str_starts_with($line, 'CREATE TABLE')) {
        $state = "CREATE TABLE";
        $terminator = ');';
        $first_line = true;

        // extract target table name
        $parts = explode(" ", $line);
        $target_table = $parts[2];

        // explode target_table by . and get last part
        $parts = explode(".", $target_table);
        $target_table = $parts[count($parts) - 1];

        if ($create_table) {
            echo "CREATE TABLE $target_table (\n";
        }
        continue;
    }

    if (str_starts_with($line, 'COPY')) {
        $state = "COPY";
        $terminator = '\.';
        $first_line = true;

        // extract target table name
        $parts = explode(" ", $line);
        $target_table = $parts[1];
        
        // make column list by discarding first two elements and two last elements
        $columns_list = join(' ', array_slice($parts, 2, count($parts) - 4));

        // explode target_table by . and get last part
        $parts = explode(".", $target_table);
        $target_table = $parts[count($parts) - 1];

        continue;
    }

    // replace ` with "
    $line = str_replace("`", '"', $line);

    // replace ' with "
    $line = str_replace("'", '"', $line);

    echo "UNRECOGNIZED: $line";
}

fclose($handle);
//fclose($output_file);