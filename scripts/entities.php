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
| Description: Collect individual entities into an entities.ent file.  |
+----------------------------------------------------------------------+

# Conventions

* `.dnt`: Simple text, "do not translate" file;
* `.txt`: Simple text, translatable, untracked file;
* `.xml`: Full XML, translatable, tracked file.

Each entitiesDir is read in order, overwriting previous defined
entities with new ones (this is inverse of XML processing, where
overwriting entities are ignored).
*/

if ( count( $argv ) < 2 || in_array( '--help' , $argv ) || in_array( '-h' , $argv ) )
{
    fwrite( STDERR , "\nUsage: {$argv[0]} entitiesDir [entitiesDir]\n\n" );
    return;
}

$filename = __DIR__ . "/../.entities.ent";  // sibling of .manual.xml
touch( $filename );                         // empty file, at minimum, and
$filename = realpath( $filename );          // realpath() fails if file not exists.

$entities = []; // all entitites, already overriden
$expected = []; // entities that are expected to be oversidem (translatins)
$override = []; // overrides stattics

$langs = [];
$detail = false;

for( $idx = 1 ; $idx < count( $argv ) ; $idx++ )
    if ( $argv[$idx] == "--detail" )
        $detail = true;
    else
        $langs[] = $argv[$idx];

if ( $detail )
    print "Creating file $filename in verbose detail mode...\n";
else
    print "Creating file $filename...";

for ( $run = 0 ; $run < count( $langs) ; $run++ )
    parseDir( $langs[$run] , $run > 0 );

dump( $filename , $entities );

if ( $detail )
{
    print "Done.\n";
}
else
{
    echo " done";
    [$all, $unt, $over] = verifyOverrides( $detail );
    if ( $unt + $over > 0 )
        echo ": $all entities, $unt untranslated, $over orerriden";
    echo ".\n";
}
exit;



function parseDir( string $dir , bool $expectedOverride )
{
    if ( ! is_dir( $dir ) )
        return; // for now. When implanted in all languages: exit( "Not a directory: $dir\n" );

    $files = scandir( $dir );

    foreach( $files as $file )
    {
        if ( str_starts_with( $file , '.' ) )
            continue;

        $path = realpath( "$dir/$file" );

        if ( is_dir( $path ) )
            continue;

        $text = file_get_contents( $path );
        validateStore( $path , $text , $expectedOverride );
    }
}

function validateStore( string $path , string $text , bool $expectedOverride )
{
    $trim = trim( $text );
    if ( strlen( $trim ) == 0 )
    {
        // Yes, there is empty entities, and they are valid entity, but not valid XML.
        // see: en/language-snippets.ent mongodb.note.queryable-encryption-preview
        push( $path , $text , $expectedOverride , true );
        return;
    }

    $frag = "<root>$text</root>";

    $dom = new DOMDocument( '1.0' , 'utf8' );
    $dom->recover = true;
    $dom->resolveExternals = false;
    libxml_use_internal_errors( true );

    $res = $dom->loadXML( $frag );

    $err = libxml_get_errors();
    libxml_clear_errors();

    foreach( $err as $item )
    {
        $msg = trim( $item->message );
        if ( str_starts_with( $msg , "Entity '" ) && str_ends_with( $msg , "' not defined" ) )
            continue;

        fwrite( STDERR , "\n  XML load failed on entity file." );
        fwrite( STDERR , "\n    Path:  $path" );
        fwrite( STDERR , "\n    Error: $msg\n" );
        return;
    }

    $inline = shouldInline( $dom );
    push( $path , $text , $expectedOverride , $inline );
}

class EntityData
{
    public function __construct(
        public string $path ,
        public string $name ,
        public string $text ,
        public bool $inline ) {}
}

function push( string $path , string $text , bool $expectedOverride , bool $inline )
{
    global $entities;
    global $expected;
    global $override;

    $info = pathinfo( $path );
    $name = $info["filename"];

    if ( $expectedOverride )
        $expected[] = $name;

    if ( ! isset( $override[$name] ) )
        $override[$name] = 0;
    else
        $override[$name]++;

    $entity = new EntityData( $path , $name , $text , $inline );
    $entities[$name] = $entity;
}

function dump( string $filename , array $entities )
{
    // In PHP 8.4 may be possible to construct an extended
    // DOMEntity class with writable properties. For now,
    // creating entities files directly as text.

    $file = fopen( $filename , "w" );
    fputs( $file , "\n<!-- DO NOT COPY - Autogenerated by entities.php -->\n\n" );

    foreach( $entities as $name => $entity )
    {
        if ( $entity->inline )
        {
            $text = str_replace( "'" , '&apos;' , $entity->text );
            fputs( $file , "<!ENTITY $name '$text'>\n\n");
        }
        else
        {
            fputs( $file , "<!ENTITY $name SYSTEM '{$entity->path}'>\n\n");
        }
    }
    fclose( $file );
}

function shouldInline( DOMDocument $dom ) : bool
{
    // Pure text entities CANNOT be SYSTEMed (or libxml fails).
    // But entities that CONTAINS elements need to be SYSTEMed
    // to avoid quotation madness.

    // Why libxml/w3c? WHY?

    $xpath = new DomXPath( $dom );
    $elems = $xpath->query( "child::*" );
    return ( $elems->length == 0 );
}

function verifyOverrides( bool $outputDetail )
{
    global $entities;
    global $expected;
    global $override;

    $countGenerated = count( $entities );
    $countExpectedOverriden = 0;
    $countUnexpectedOverriden = 0;

    foreach( $entities as $name => $text )
    {
        $times = $override[$name];

        if ( isset( $expected[$name] ) )
        {
            if ( $times != 1 )
            {
                $countExpectedOverriden++;
                if ( $outputDetail )
                    print "Expected   override entity $name overriden $times times.\n";
            }
        }
        else
        {
            if ( $times != 0 )
            {
                $countUnexpectedOverriden++;
                if ( $outputDetail )
                    print "Unexpected override entity $name overriden $times times.\n";
            }
        }
    }

    return [$countGenerated, $countExpectedOverriden, $countUnexpectedOverriden];
}
