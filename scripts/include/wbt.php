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
| Description: XML fragments, a.k.a. "well balanced texts" functions.  |
+----------------------------------------------------------------------+

The XML files of PHP Manual sources are not valid XML files. In fact,
the files are at most *invalid* XML fragment files, or really,
well-balanced regions with *undefined* DTD entities, that in turn makes
then invalid in all levels.

There is no know way to configure PHP's XML stack to accept any file or
fragment with undefined DTD entities, at least before PHP 8.4, so the
functions below exists to circuvent that.

See: https://www.w3.org/TR/xml-fragment/#defn-well-balanced

*/

function wbt_load_root( string $text , callable $entityCallback = null ) : DOMDocument
{
//                                       What's Opera, Doc?
//                                            -- Bugs Bunny
//
//                                         Kill the wabbit!
//                                            -- Elmer Fudd

    $text = str_replace( "&" , "&amp;" , $text );
    $frag = false;

    $dom = new DOMDocument( '1.0' , 'utf8' );
    $ret = $dom->loadXML( $text );

    if ( ! $ret )
    {
        $text = '<frag>' . $text . '</frag>';
        $frag = true;
        $ret = $dom->loadXML( $text );
    }
    if ( ! $ret )
        if ( $flag )
            throw new Exception( "Double invalid well-balanced text.\n" );
        else
            throw new Exception( "Double invalid XML fragment.\n" );

    $xpath = new DOMXPath( $dom );
    $nodes = $xpath->query( "//text()" );
    foreach( $nodes as $node )
        wbt_entityref_rerere( $node , $entityCallback );
    unset( $nodes );

    // TODO: Also //@ (attributes)?

    return $dom;
}

function wbt_entityref_rerere( DOMNode $node , callable $entityCallback = null )
{
    // Resolve
    // Restore
    // Recurse

    if ( $node == null )
        return;

    $text = $node->nodeValue;

    $pos1 = strpos( $text , "&" );
    if ( $pos1 === false )
        return;

    $pos2 = strpos( $text , ";" , $pos1 + 1 );
    if ( $pos2 === false )
        return;

    $doc = $node->ownerDocument;
    $name = substr( $text , $pos1 + 1 , $pos2 - $pos1 - 1 );

    // If there is text around the entity about to be recreated,
    // these need to be readded as text nodes before replacing
    // the original node by the raw entity.

    $prefix = substr( $text , 0 , $pos1 );
    $suffix = substr( $text , $pos2 + 1 );

    // $node->replaceWith() appers to be broken
    // over XPath //text() listing. Also,
    // $node->nextSibling is always null after
    // $node->replaceWith OR any sibling manipulation.

    // Sigh. Lets keep a hard reference here.
    $nextNode = null;

    if ( $prefix != "" )
    {
        $prevNode = $doc->createTextNode( $prefix );
        $node->before( $prevNode );
    }
    if ( $suffix != "" )
    {
        $nextNode = $doc->createTextNode( $suffix );
        $node->after( $nextNode );
    }

    $replace = null;

    if ( $entityCallback == null )
    {
        // Restore
        $replace = $doc->createEntityReference( $name );
    }
    else
    {
        // Resolve
        $text = $entityCallback( $name );
        //$replace = wbt_load_append( $doc , $text ); //TODO
        $replace = $doc->createEntityReference( $name );
    }

    $node->before( $replace );
    $node->parentNode->removeChild( $node );

    if ( $nextNode != null )
        wbt_entityref_rerere( $nextNode );
}

function wbt_entityref_list( DOMDocument $doc ) : array
{
    // Nodes of type XML_ENTITY_REF_NODE are fully
    // invisible to any form of XPath listing, so we
    // need to bruteforce it here.

    $ret = [];
    wbt_entityref_list_recurse( $doc , $ret );
    return $ret;
}

function wbt_entityref_list_recurse( DOMNode $node , array & $ret )
{
    if ( $node->nodeType == XML_ENTITY_REF_NODE )
        $ret[] = $node;
    foreach( $node->childNodes as $node )
        wbt_entityref_list_recurse( $node , $ret );
}
