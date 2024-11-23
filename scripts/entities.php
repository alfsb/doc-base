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
| Description: Collect individual entities into an entities.ent file.  |
+----------------------------------------------------------------------+

# Mental model, or things that I would liked to know 20 years prior

XML Entity processing has more in common with DOMDocumentFragment than
DOMElement. In other words, simple text and multi roots XML entities
are valid <!ENTITY> contents, whereas they are not valid XML documents.

Also, namespaces do not automatically "cross" between a parent
document and their includes, even if they are included in the same
file, as local textual entities. They are, for all intended purposes,
separated documents, with separated namespaces and have *expected*
different *default* namespaces.

So each one of, possibly multiple, "root" XML elements inside an
fragment need to be annotated with default namespace, even if the
"root" element occurs surrounded by text. For example:

- "text<tag>text</tag>", need one namespace, or it is invalid, and;
- "<tag></tag><tag></tag", need TWO namespaces, or it is also invalid.

# Individual tracked entities, or `.xml` files at `entities/`

As explained above, the individual entity contents are not really
valid XML *documents*, they are only at most valid XML *fragments*.

Yet, individual entities are stored in entities/ as .xml files, for
two reasons: first, text editors in general can highlights XML syntax,
and second, this allows normal revision tracking on then, without
requiring weird changes on `revcheck.php`.

# Small entities, group tracked (future)

For very small textual entities, down to simple text words, that may
never change, having tracking for each instance is an overkill.

It's planned to have new `manual.ent` and `website.ent` files
on each doc language, that internally are valid XML documents and
also replicates namespace declarations used on manual.xml.in, so
it will possible migrate the current <!ENTITY> infrastructure
to something that is more consumable for XML toolage (and will
avoid most of it not all XML namespacing hell).

These small files are to be splited into entities/ as individial
.tmp text files, for normal inclusion on manual.

*/

ini_set( 'display_errors' , 1 );
ini_set( 'display_startup_errors' , 1 );
error_reporting( E_ALL );

if ( count( $argv ) < 2 || in_array( '--help' , $argv ) || in_array( '-h' , $argv ) )
{
    fwrite( STDERR , "\nUsage: {$argv[0]} entitiesDir [entitiesDir]\n\n" );
    return;
}

$filename = __DIR__ . "/../.entities.ent";  // sibling of .manual.xml
touch( $filename );                         // empty file at minimum, and because
$filename = realpath( $filename );          // realpath() fails if file does not exist.

$entities = []; // all entities, already replaced
$expected = []; // entities that are expected to be replaced/translated
$foundcnt = []; // tracks how many times entity name was found

$langs = [];
$detail = false;

for( $idx = 1 ; $idx < count( $argv ) ; $idx++ )
    if ( $argv[$idx] == "--detail" )
        $detail = true;
    else
        $langs[] = $argv[$idx];

if ( ! $detail )
    print "Creating file $filename...";

for ( $run = 0 ; $run < count( $langs ) ; $run++ )
    parseDir( $langs[$run] , ( count( $langs ) && $run == 0 ) );

dump( $filename , $entities );
[$all, $unt, $over] = verifyReplaced( $detail );

if ( ! $detail )
{
    echo " done";
    if ( $unt + $over > 0 )
        echo ": $all entities, $unt untranslated, $over overwrites.";
    echo "\n";
}
exit;

function parseDir( string $dir , bool $expectedReplaced )
{
    if ( ! is_dir( $dir ) )
        exit( "Not a directory: $dir\n" );

    $count = 0;
    $files = scandir( $dir );

    foreach( $files as $file )
    {
        if ( str_starts_with( $file , '.' ) )
            continue;

        $path = realpath( "$dir/$file" );

        if ( is_dir( $path ) )
            continue;

        $text = file_get_contents( $path );
        validateStore( $path , $text , $expectedReplaced );
        $count++;
    }

    global $detail;
    if ( $detail )
        echo "$count files on $dir\n";
}

function validateStore( string $path , string $text , bool $expectedReplaced )
{
    $trim = trim( $text );
    if ( strlen( $trim ) == 0 )
    {
        // Yes, there are empty entities, and they are valid entities, but not valid XML.
        // see: en/language-snippets.ent mongodb.note.queryable-encryption-preview
        push( $path , $text , $expectedReplaced , true );
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

    push( $path , $text , $expectedReplaced );
}

class EntityData
{
    public function __construct(
        public string $path ,
        public string $name ,
        public string $text ) {}
}

function push( string $path , string $text , bool $expectedReplaced )
{

    global $entities;
    global $expected;
    global $foundcnt;

    $info = pathinfo( $path );
    $name = $info["filename"];

    if ( $expectedReplaced )
        $expected[] = $name;

    if ( ! isset( $foundcnt[$name] ) )
        $foundcnt[$name] = 1;
    else
        $foundcnt[$name]++;

    $entity = new EntityData( $path , $name , $text );
    $entities[$name] = $entity;
}

function dump( string $filename , array $entities )
{
    // In PHP 8.4 may be possible to construct an extended
    // DOMEntity class with writable properties. For now,
    // creating entities files directly by hand.

    $file = fopen( $filename , "w" );
    fputs( $file , "\n<!-- DO NOT COPY - Autogenerated by entities.php -->\n\n" );

    foreach( $entities as $name => $entity )
    {
        $text = $entity->text;

        $quote = "";
        $posSingle = strpos( $text , "'" );
        $posDouble = strpos( $text , '"' );

        if ( $posSingle === false )
            $quote = "'";
        if ( $posDouble === false )
            $quote = '"';

        // If the text contains mixed quoting, keeping it
        // as an external file to avoid (re)quotation hell.

        if ( $quote == "" )
            fputs( $file , "<!ENTITY $name SYSTEM '{$entity->path}'>\n\n" );
        else
            fputs( $file , "<!ENTITY $name {$quote}{$text}{$quote}>\n\n" );
    }

    fclose( $file );
}

function verifyReplaced( bool $outputDetail )
{
    global $entities;
    global $expected;
    global $foundcnt;

    $countUntranslated = 0;
    $countConstantChanged = 0;

    foreach( $entities as $name => $text )
    {
        $replaced = $foundcnt[$name] - 1 ;
        $expectedReplaced = in_array( $name , $expected );

        if ( $expectedReplaced && $replaced != 1 )
        {
            $countUntranslated++;
            if ( $outputDetail )
                print "Expected translated, replaced $replaced times:\t$name\n";
        }

        elseif ( ! $expectedReplaced && $replaced != 0 )
        {
            $countConstantChanged++;
            if ( $outputDetail )
                print "Unexpected replaced, replaced $replaced times:\t$name\n";
        }
    }

    return [count( $entities ), $countUntranslated, $countConstantChanged];
}
