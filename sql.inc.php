<?php
if ( ! $error && $_POST["export"])
{
    dump_headers("sql");
    $adminer->dumpTable("", "");
    $adminer->dumpData("", "table", $_POST["query"]);
    exit;
}

restart_session();
$history_all = &get_session("queries");
$history = &$history_all[DB];
if ( ! $error && $_POST["clear"])
{
    $history = [];
    redirect(remove_from_uri("history"));
}

page_header((isset($_GET["import"]) ? lang('Import') : lang('SQL command')), $error);

$dataList = [];

if ( ! $error && $_POST)
{
    $fp = false;
    if ( ! isset($_GET["import"]))
    {
        $query = $_POST["query"];
    }
    elseif ($_POST["webfile"])
    {
        $fp = @fopen((file_exists("adminer.sql")
            ? "adminer.sql"
            : "compress.zlib://adminer.sql.gz"
        ), "rb");
        $query = ($fp ? fread($fp, 1e6) : false);
    }
    else
    {
        $query = get_file("sql_file", true);
    }

    if (is_string($query))
    { // get_file() returns error as number, fread() as false
        if (function_exists('memory_get_usage'))
        {
            @ini_set("memory_limit", max(ini_bytes("memory_limit"), 2 * strlen($query) + memory_get_usage() + 8e6)); // @ - may be disabled, 2 - substr and trim, 8e6 - other variables
        }

        if ($query != "" && strlen($query) < 1e6)
        { // don't add big queries
            $q = $query . (preg_match("~;[ \t\r\n]*\$~", $query) ? "" : ";"); //! doesn't work with DELIMITER |
            if ( ! $history || reset(end($history)) != $q)
            { // no repeated queries
                restart_session();
                $history[] = [$q, time()]; //! add elapsed time
                set_session("queries", $history_all); // required because reference is unlinked by stop_session()
                stop_session();
            }
        }

        $space = "(?:\\s|/\\*.*\\*/|(?:#|-- )[^\n]*\n|--\r?\n)";
        $delimiter = ";";
        $offset = 0;
        $empty = true;
        $connection2 = connect(); // connection for exploring indexes and EXPLAIN (to not replace FOUND_ROWS()) //! PDO - silent error
        if (is_object($connection2) && DB != "")
        {
            $connection2->select_db(DB);
        }
        $commands = 0;
        $errors = [];
        $line = 0;
        $parse = '[\'"' . ($jush == "sql" ? '`#' : ($jush == "sqlite" ? '`[' : ($jush == "mssql" ? '[' : ''))) . ']|/\\*|-- |$' . ($jush == "pgsql" ? '|\\$[^$]*\\$' : '');
        $total_start = microtime(true);
        parse_str($_COOKIE["adminer_export"], $adminer_export);
        $dump_format = $adminer->dumpFormat();
        unset($dump_format["sql"]);

        while ($query != "")
        {
            if ( ! $offset && preg_match("~^$space*DELIMITER\\s+(\\S+)~i", $query, $match))
            {
                $delimiter = $match[1];
                $query = substr($query, strlen($match[0]));
            }
            else
            {
                preg_match('(' . preg_quote($delimiter) . "\\s*|$parse)", $query, $match, PREG_OFFSET_CAPTURE, $offset); // should always match
                list($found, $pos) = $match[0];
                if ( ! $found && $fp && ! feof($fp))
                {
                    $query .= fread($fp, 1e5);
                }
                else
                {
                    if ( ! $found && rtrim($query) == "")
                    {
                        break;
                    }
                    $offset = $pos + strlen($found);

                    if ($found && rtrim($found) != $delimiter)
                    { // find matching quote or comment end
                        while (preg_match('(' . ($found == '/*' ? '\\*/' : ($found == '[' ? ']' : (preg_match('~^-- |^#~', $found) ? "\n" : preg_quote($found) . "|\\\\."))) . '|$)s', $query, $match, PREG_OFFSET_CAPTURE, $offset))
                        { //! respect sql_mode NO_BACKSLASH_ESCAPES
                            $s = $match[0][0];
                            if ( ! $s && $fp && ! feof($fp))
                            {
                                $query .= fread($fp, 1e5);
                            }
                            else
                            {
                                $offset = $match[0][1] + strlen($s);
                                if ($s[0] != "\\")
                                {
                                    break;
                                }
                            }
                        }

                    }
                    else
                    { // end of a query
                        $empty = false;
                        $q = substr($query, 0, $pos);
                        $commands++;
                        $print = "<pre id='sql-$commands'><code class='jush-$jush'>" . shorten_utf8(trim($q), 1000) . "</code></pre>\n";
                        if ( ! $_POST["only_errors"])
                        {
                            echo $print;
                            ob_flush();
                            flush(); // can take a long time - show the running query
                        }
                        $start = microtime(true);
                        //! don't allow changing of character_set_results, convert encoding of displayed query
                        if ($connection->multi_query($q) && is_object($connection2) && preg_match("~^$space*USE\\b~isU", $q))
                        {
                            $connection2->query($q);
                        }

                        do
                        {
                            $result = $connection->store_result();
                            $time = " <span class='time'>(" . format_time($start) . ")</span>"
                                . (strlen($q) < 1000 ? " <a href='" . h(ME) . "sql=" . urlencode(trim($q)) . "'>" . lang('Edit') . "</a>" : "") // 1000 - maximum length of encoded URL in IE is 2083 characters
                            ;

                            if ($connection->error)
                            {
                                echo($_POST["only_errors"] ? $print : "");
                                echo "<p class='error'>" . lang('Error in query') . ($connection->errno ? " ($connection->errno)" : "") . ": " . error() . "\n";
                                $errors[] = " <a href='#sql-$commands'>$commands</a>";
                                if ($_POST["error_stops"])
                                {
                                    break 2;
                                }

                            }
                            elseif (is_object($result))
                            {
                                $limit = $_POST["limit"];
                                $orgtables = select($result, $connection2, [], $limit, $dataList, isset($_POST['show_result_table']) && $_POST['show_result_table']);

                                if ( ! $_POST["only_errors"])
                                {
                                    echo "<form action='' method='post'>\n";
                                    $num_rows = $result->num_rows;
                                    echo "<p>" . ($num_rows ? ($limit && $num_rows > $limit ? lang('%d / ', $limit) : "") . lang('%d row(s)', $num_rows) : "");
                                    echo $time;
                                    $id = "export-$commands";
                                    $export = ", <a href='#$id' onclick=\"return !toggle('$id');\">" . lang('Export') . "</a><span id='$id' class='hidden'>: "
                                        . html_select("output", $adminer->dumpOutput(), $adminer_export["output"]) . " "
                                        . html_select("format", $dump_format, $adminer_export["format"])
                                        . "<input type='hidden' name='query' value='" . h($q) . "'>"
                                        . " <input type='submit' name='export' value='" . lang('Export') . "'><input type='hidden' name='token' value='$token'></span>\n";
                                    if ($connection2 && preg_match("~^($space|\\()*SELECT\\b~isU", $q) && ($explain = explain($connection2, $q)))
                                    {
                                        $id = "explain-$commands";
                                        echo ", <a href='#$id' onclick=\"return !toggle('$id');\">EXPLAIN</a>$export";
                                        echo "<div id='$id' class='hidden'>\n";
                                        select($explain, $connection2, $orgtables);
                                        echo "</div>\n";
                                    }
                                    else
                                    {
                                        echo $export;
                                    }
                                    echo "</form>\n";
                                }

                            }
                            else
                            {
                                if (preg_match("~^$space*(CREATE|DROP|ALTER)$space+(DATABASE|SCHEMA)\\b~isU", $q))
                                {
                                    restart_session();
                                    set_session("dbs", null); // clear cache
                                    stop_session();
                                }
                                if ( ! $_POST["only_errors"])
                                {
                                    echo "<p class='message' title='" . h($connection->info) . "'>" . lang('Query executed OK, %d row(s) affected.', $connection->affected_rows) . "$time\n";
                                }
                            }

                            $start = microtime(true);
                        }
                        while ($connection->next_result());

                        $line += substr_count($q . $found, "\n");
                        $query = substr($query, $offset);
                        $offset = 0;
                    }

                }
            }
        }

        if ($empty)
        {
            echo "<p class='message'>" . lang('No commands to execute.') . "\n";
        }
        elseif ($_POST["only_errors"])
        {
            echo "<p class='message'>" . lang('%d query(s) executed OK.', $commands - count($errors));
            echo " <span class='time'>(" . format_time($total_start) . ")</span>\n";
        }
        elseif ($errors && $commands > 1)
        {
            echo "<p class='error'>" . lang('Error in query') . ": " . implode("", $errors) . "\n";
        }
        //! MS SQL - SET SHOWPLAN_ALL OFF

    }
    else
    {
        echo "<p class='error'>" . upload_error($query) . "\n";
    }
}

$chart = isset($_POST['chart']) ? $_POST['chart'] : '';
?>

<form action="" method="post" enctype="multipart/form-data" id="form">
    <?php
    $execute = "<input type='submit' value='" . lang('Execute') . "' title='Ctrl+Enter'>";
    if ( ! isset($_GET["import"]))
    {
        $q = $_GET["sql"]; // overwrite $q from if ($_POST) to save memory
        if ($_POST)
        {
            $q = $_POST["query"];
        }
        elseif ($_GET["history"] == "all")
        {
            $q = $history;
        }
        elseif ($_GET["history"] != "")
        {
            $q = $history[$_GET["history"]][0];
        }
        echo "<p>";
        textarea("query", $q, 20);
        echo($_POST ? "" : "<script type='text/javascript'>focus(document.getElementsByTagName('textarea')[0]);</script>\n");

        echo '<hr>';

        echo '<h4>数据图表 - 选项设置</h4>';

        echo "<fieldset>";
        echo "<legend>图表格式</legend>";

        $chartTypes = [
            'line'    => '折线图',
            'bar'     => '柱形图',
            'pie'     => '饼状图',
            'scatter' => '散点图',
            'radar'   => '雷达图',
        ];
        $hasChartType = false;
        foreach ($chartTypes as $chartType => $chartTypeName)
        {
            ?><label>
            <input type="radio" name="chart" value="<?php echo $chartType ?>"
                <?php
                if ($chart == $chartType)
                {
                    echo 'checked';
                }
                else
                {
                    $hasChartType = true;
                }
                ?>
                />
            <?php echo $chartTypeName ?>
            </label><br><?php
        }
        echo "<label><input type='radio' name='chart' value='' " . ($hasChartType ? '' : ' checked') . " /> 无</label>";
        echo "</fieldset>";

        echo "<fieldset>";
        echo "<legend>标题选项</legend>";

        echo "<select name='title[show]'>";
        foreach (['显示' => 'true', '隐藏' => 'false'] as $k => $v)
        {
            ?>
        <option
            value="<?php echo $v ?>" <?php echo isset($_POST['title']['show']) && $_POST['title']['show'] == $v ? 'selected' : '' ?>>
            <?php echo $k ?></option><?php
        }
        echo "</select>&nbsp;&nbsp;";

        echo "<input type='text' name='title[text]' placeholder='标题文本' value='" . (
            isset($_POST['title']['text']) ? $_POST['title']['text'] : ''
            ) . "' /><hr />";

        echo "X：<select name='title[x]'>";
        foreach (['左侧' => 'left', '右侧' => 'right', '中间' => 'center'] as $k => $v)
        {
            ?>
        <option
            value="<?php echo $v ?>" <?php echo isset($_POST['title']['x']) && $_POST['title']['x'] == $v ? 'selected' : '' ?>>
            <?php echo $k ?></option><?php
        }
        echo "</select>&nbsp;&nbsp;&nbsp;";

        echo "Y：<select name='title[y]'>";
        foreach (['顶部' => 'top', '底部' => 'bottom', '中间' => 'center'] as $k => $v)
        {
            ?>
        <option
            value="<?php echo $v ?>" <?php echo isset($_POST['title']['y']) && $_POST['title']['y'] == $v ? 'selected' : '' ?>>
            <?php echo $k ?></option><?php
        }
        echo "</select><hr>";

        echo "结果表格：<select name='show_result_table'>";
        foreach (['显示' => 1, '隐藏' => 0] as $k => $v)
        {
            ?>
        <option
            value="<?php echo $v ?>" <?php echo isset($_POST['show_result_table']) && $_POST['show_result_table'] == $v ? 'selected' : '' ?>>
            <?php echo $k ?></option><?php
        }
        echo "</select>";

        echo "</fieldset>";

        echo "<fieldset>";
        echo "<legend>图例</legend>";

        echo "<select name='legend[show]'>";
        foreach (['显示' => 'true', '隐藏' => 'false'] as $k => $v)
        {
            ?>
        <option
            value="<?php echo $v ?>" <?php echo isset($_POST['legend']['show']) && $_POST['legend']['show'] == $v ? 'selected' : '' ?>>
            <?php echo $k ?></option><?php
        }
        echo "</select>";

        echo "&nbsp;&nbsp;&nbsp;布局：<select name='legend[orient]'>";
        foreach (['水平布局' => 'horizontal', '垂直布局' => 'vertical'] as $k => $v)
        {
            ?>
        <option
            value="<?php echo $v ?>" <?php echo isset($_POST['legend']['orient']) && $_POST['legend']['orient'] == $v ? 'selected' : '' ?>>
            <?php echo $k ?></option><?php
        }
        echo "</select><hr />";

        echo "X：<select name='legend[x]'>";
        foreach (['中间' => 'center', '左侧' => 'left', '右侧' => 'right'] as $k => $v)
        {
            ?>
        <option
            value="<?php echo $v ?>" <?php echo isset($_POST['legend']['x']) && $_POST['legend']['x'] == $v ? 'selected' : '' ?>>
            <?php echo $k ?></option><?php
        }
        echo "</select>&nbsp;&nbsp;&nbsp;";

        echo "Y：<select name='legend[y]'>";
        foreach (['顶部' => 'top', '底部' => 'bottom', '中间' => 'center'] as $k => $v)
        {
            ?>
        <option
            value="<?php echo $v ?>" <?php echo isset($_POST['legend']['y']) && $_POST['legend']['y'] == $v ? 'selected' : '' ?>>
            <?php echo $k ?></option><?php
        }
        echo "</select><hr>";

        echo "<input type='text' name='legend[data]' placeholder='选项' value='" . (isset($_POST['legend']['data']) ? $_POST['legend']['data'] : '') . "' />";
        echo "</fieldset>";

        echo "<fieldset>";
        echo "<legend>数据</legend>";

        echo "X轴取值：<select name='x[field]'>";
        foreach ($dataList['fields'] as $field)
        {
            echo "<option value='$field' " . (isset($_POST['x']['field']) && $_POST['x']['field'] == $field ? 'selected' : '') . ">$field</option>";
        }
        echo "</select><br />";

        echo "<input type='text' name='x[name]' placeholder='X轴标题' value='" . (isset($_POST['x']['name']) ? $_POST['x']['name'] : '') . "' />";
        echo "<hr />";

        echo "Y轴取值：<select name='y[field]'>";
        foreach ($dataList['fields'] as $field)
        {
            echo "<option value='$field' " . (isset($_POST['y']['field']) && $_POST['y']['field'] == $field ? 'selected' : '') . ">$field</option>";
        }
        echo "</select><br />";

        echo "<input type='text' name='y[name]' placeholder='Y轴标题' value='" . (isset($_POST['y']['name']) ? $_POST['y']['name'] : '') . "' />";
        echo "</fieldset>";

        echo '<hr>';

        if ($chart)
        {
            \Hisune\EchartsPHP\Config::$dist = '//cdn.bootcss.com/echarts/2.2.7';
            $chartInstance = new \Hisune\EchartsPHP\ECharts();
            //var_dump($dataList, $_POST);die();

            $chartInstance->title = $_POST['title'];
            $chartInstance->title['show'] = $chartInstance->title['show'] == 'true';

            $chartInstance->tooltip = [
                'show' => true,
            ];

            // 处理legend
            $_POST['legend']['data'] = explode(',', $_POST['legend']['data']);
            $chartInstance->legend = $_POST['legend'];
            $chartInstance->legend['show'] = $chartInstance->legend['show'] == 'true';

            $xPost = (array) $_POST['x'];
            $x = [
                'type' => 'category',
                'name' => isset($xPost['name']) && $xPost['name'] ? $xPost['name'] : $xPost['field'],
                'data' => [],
            ];
            foreach ($dataList['records'] as $record)
            {
                $x['data'][] = $record[$xPost['field']];
            }
            $chartInstance->xAxis = [
                $x,
            ];

            $chartInstance->yAxis = [
                ['type' => 'value'],
            ];

            $yPost = (array) $_POST['y'];
            $chartInstance->series = [];
            $chartInstance->series[0] = [
                'type' => strip_tags($chart),
                'name' => isset($yPost['name']) && $yPost['name'] ? $yPost['name'] : $yPost['field'],
                'data' => [],
            ];

            if ($chart == 'pie')
            {
                $chartInstance->series[0]['center'] = ['50%', '60%'];
                $chartInstance->series[0]['radius'] = '55%';
                $chartInstance->legend['data'] = [];
                $chartInstance->calculable = true;
                unset($chartInstance->xAxis);
                unset($chartInstance->yAxis);
            }
            foreach ($dataList['records'] as $record)
            {
                $i = $record[$yPost['field']];
                if (is_numeric($i))
                {
                    $i = intval($i);
                }

                if ($chart == 'pie')
                {
                    $name = $record[$xPost['field']];
                    $chartInstance->series[0]['data'][] = [
                        'value' => $i,
                        'name'  => $name,
                    ];
                    $chartInstance->legend['data'][] = $name;
                }
                else
                {
                    $chartInstance->series[0]['data'][] = $i;
                }
            }

            echo $chartInstance->render('simple-custom-id');
        }

        echo '<hr>';


        echo "<p>$execute\n";
        echo lang('Limit rows') . ": <input type='number' name='limit' class='size' value='" . h($_POST ? $_POST["limit"] : $_GET["limit"]) . "'>\n";

    }
    else
    {
        echo "<fieldset><legend>" . lang('File upload') . "</legend><div>";
        echo(ini_bool("file_uploads")
            ? "SQL (&lt; " . ini_get("upload_max_filesize") . "B): <input type='file' name='sql_file[]' multiple>\n$execute" // ignore post_max_size because it is for all form fields together and bytes computing would be necessary
            : lang('File uploads are disabled.')
        );
        echo "</div></fieldset>\n";
        echo "<fieldset><legend>" . lang('From server') . "</legend><div>";
        echo lang('Webserver file %s', "<code>adminer.sql" . (extension_loaded("zlib") ? "[.gz]" : "") . "</code>");
        echo ' <input type="submit" name="webfile" value="' . lang('Run file') . '">';
        echo "</div></fieldset>\n";
        echo "<p>";
    }

    echo checkbox("error_stops", 1, ($_POST ? $_POST["error_stops"] : isset($_GET["import"])), lang('Stop on error')) . "\n";
    echo checkbox("only_errors", 1, ($_POST ? $_POST["only_errors"] : isset($_GET["import"])), lang('Show only errors')) . "\n";
    echo "<input type='hidden' name='token' value='$token'>\n";

    if ( ! isset($_GET["import"]) && $history)
    {
        print_fieldset("history", lang('History'), $_GET["history"] != "");
        for ($val = end($history); $val; $val = prev($history))
        { // not array_reverse() to save memory
            $key = key($history);
            list($q, $time, $elapsed) = $val;
            echo '<a href="' . h(ME . "sql=&history=$key") . '">' . lang('Edit') . "</a>"
                . " <span class='time' title='" . @date('Y-m-d', $time) . "'>" . @date("H:i:s", $time) . "</span>" // @ - time zone may be not set
                . " <code class='jush-$jush'>" . shorten_utf8(ltrim(str_replace("\n", " ", str_replace("\r", "", preg_replace('~^(#|-- ).*~m', '', $q)))), 80, "</code>")
                . ($elapsed ? " <span class='time'>($elapsed)</span>" : "")
                . "<br>\n";
        }
        echo "<input type='submit' name='clear' value='" . lang('Clear') . "'>\n";
        echo "<a href='" . h(ME . "sql=&history=all") . "'>" . lang('Edit all') . "</a>\n";
        echo "</div></fieldset>\n";
    }
    ?>
</form>