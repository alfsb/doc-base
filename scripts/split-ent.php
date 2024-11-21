<?php
/*
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
| Description: Split an .ent file into individual files.               |
+----------------------------------------------------------------------+

# With or withour tracking

For spliting files from doc-base and doc-en, no revtag or hash tracking
is necessary. But for translations, further processing is necessary to
fill revtags with... some data.

This is a translation leve decision. To have hash and maintainer blank
filled, to be manually filled with text revision, or to using the
deduce algorithm to fill hash and maintainers.

*/

if ( count( $argv ) < 4 )
     die(" Syntax: php $argv[0] infile outdir ext [maintainer]\n" );

$infile = $argv[1];
$outdir = $argv[2];
$ext    = $argv[3];
$maintainer = $argv[4] ?? "";

$content = file_get_contents( $infile );
$entities = [];

// Parse

$pos1 = 0;
while ( true )
{
    $pos1 = strpos( $content , "<!ENTITY", $pos1 );
    if ( $pos1 === false ) break;

    $posS = strpos( $content , "'" , $pos1 );
    $posD = strpos( $content , '"' , $pos1 );

    if ( $posS < $posD )
        $q = "'";
    else
        $q = '"';

    $pos1 += 8;
    $pos2 = min( $posS , $posD ) + 1;
    $pos3 = strpos( $content , $q , $pos2 );

    $name = substr( $content , $pos1 , $pos2 - $pos1 - 1 );
    $text = substr( $content , $pos2 , $pos3 - $pos2 );

    $name = trim( $name );

    $entities[$name] = $text;
}

// Check

foreach( $entities as $name => $text )
{
    $file = "$outdir/$name.$ext";
    if ( file_exists( $file ) )
        exit( "Name colision: $file\n" );
}

// Write

foreach( $entities as $name => $text )
{
    $file = "$outdir/$name.$ext";
    file_put_contents( $file , $text );
    print "Generated $file\n";
}
