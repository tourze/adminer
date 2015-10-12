<?php

if (empty($_GET['file']))
{
    ob_start(function ($s)
    {
        return preg_replace_callback('#(<(link|script)\s[^>]*(href|src)=")(adminer\.css|externals/.+|static/.+)"#U', function ($m)
        {
            return $m[1] . '?file=' . urlencode($m[4]) . '"';
        }, $s);
    }, 4096);

}
elseif (preg_match('#^(default|externals|static(/\w[\w.-]*)+)\.(\w+)\z#', $_GET['file'], $m))
{
    if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']))
    {
        header('HTTP/1.1 304 Not Modified');
        exit;
    }

    header('Expires: ' . gmdate('D, d M Y H:i:s', strtotime('1 month')) . ' GMT');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');

    $types = ['css' => 'text/css', 'js' => 'text/javascript', 'gif' => 'image/gif', 'png' => 'image/png'];
    if (isset($types[$m[3]]))
    {
        header('Content-Type: ' . $types[$m[3]]);
    }
    @readfile(__DIR__ . '/' . $_GET['file']);
    exit;
}


function adminer_object()
{

    foreach (glob(dirname(__FILE__) . '/plugins/*.php') as $filename)
    {
        include_once $filename;
    }

    $plugins = [
        new AdminerAutocomplete,
        new AdminerCollations,
        new AdminerDisableJush,
        new AdminerDumpJson,
        new AdminerDumpXml,
        new AdminerDumpZip,
        new AdminerEnumOption,
        new AdminerFrames,
        new AdminerJsonPreview,
        new AdminerRemoteColor,
        new AdminerResultCharts,
        new AdminerSaveMenuPos,
        new AdminerSimpleMenu,
    ];

    return new AdminerPlugin($plugins);
}

include_once __DIR__ . '/vendor/autoload.php';
include dirname(__FILE__) . '/entry.php';
