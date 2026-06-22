<?php /*
+----------------------------------------------------------------------+
| Copyright (c) 1997-2023 The PHP Group                                |
+----------------------------------------------------------------------+
| This source file is subject to version 3.01 of the PHP license,      |
| that is bundled with this package in the file LICENSE, and is        |
| available through the world-wide-web at the following url:           |
| https://www.php.net/license/3_01.txt.                                |
| If you did not receive a copy of the PHP license and are unable to   |
| obtain it through the world-wide-web, please send a note to          |
| license@php.net, so we can mail you a copy immediately.              |
+----------------------------------------------------------------------+
| Authors:     André L F S Bacci <ae php.net>                          |
+----------------------------------------------------------------------+
| Description: General path related functions, used in doc-base.       |
+----------------------------------------------------------------------+
*/

function realpain( string $path , bool $touch = false , bool $mkdir = false ) : string
{
    // pain is real

    // Care for external XML tools (realpath() everywhere).
    // Care for Windows builds (forward slashes everywhere).
    // Avoid `cd` and chdir() like the plague.

    $path = str_replace( "\\" , '/' , $path );

    if ( $mkdir && ! file_exists( $path ) )
        mkdir( $path , recursive: true );

    if ( $touch && ! file_exists( $path ) )
        touch( $path );

    $res = realpath( $path );
    if ( is_string( $res ) )
        $path = str_replace( "\\" , '/' , $res );

    return $path;
}

function path_list_sort_recurse( $path )
{
    $ret = [];
    path_list_sort_recurse_accumulate( $path , $ret );
    sort( $ret );
    return $ret;
}

function path_list_sort_recurse_accumulate( $dir , array & $ret )
{
    $list = scandir( $dir );

    foreach( $list as $item )
    {
        if ( str_starts_with( $item , '.' ) )
            continue;

        $item = "$dir/$item";
        $ret[] = $item;

        if ( is_dir( $item ) )
            path_list_sort_recurse_accumulate( $item , $ret );
    }
}