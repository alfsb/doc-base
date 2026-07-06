<?php /*
+----------------------------------------------------------------------+
| Copyright (c) 1997-2026 The PHP Group                                |
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
| Description: XML fragments, a.k.a. "well balanced regions" classes.  |
+----------------------------------------------------------------------+

//                                                    What's Opera, Doc?
//                                                         -- Bugs Bunny
//
//                                                      Kill the wabbit!
//                                                         -- Elmer Fudd

The .xml files of PHP Manual sources are not well-formed XML files. In
fact, the files are at most *invalid* XML fragment files, or really,
well-balanced regions with *undefined* DTD entities, that in turn makes
them invalid in all levels.

There is no known way to configure PHP's XML stack to accept any file or
fragment with undefined DTD entities, and keep them, at least before
PHP 8.4, so the classes below exist to circumvent that.

This parser recreates any undefined entities as is, in place, so the
text can be further processed with the XML stack without other changes.

These invalid XML files and fragments, parsed and with entities recreated,
are called here as "well-balanced text", or WBT for short.

The XbtParser below is used for for general loading of individual WBT
files and texts, and also to a experimental replacement of XML assembly
step of php/doc-base/configure.php, that uses libxml2, that in turn has
hardcoded limits, already exceeded by PHP Manual building.

See: https://www.w3.org/TR/xml-fragment/#defn-fragment-body

*/

class WbtParser
{
    public function parseFile( string $filename , callable $entityCallback )
    {
        $contents = $this->loadFile( $filename );
        return $this->parseText( $contents , $entityCallback );
    }

    public function parseText( DOMDocument $doc , callable $entityCallback )
    {
        $nodes = $this->listTextNodes( $doc );
        foreach( $nodes as $node )
            $this->parseNode( $node , $entityCallback );

        $this->revertAmpersands( $doc );
        return $doc->saveXML();
    }

    public function parseNode( DOMNode $node , callable $entityCallback )
    {
        $type = $node->nodeType;
        if ( $type != XML_TEXT_NODE )
            throw new Exception( "Only text nodes: {$type}." );

        $text = $node->nodeValue;
        $pos1 = strpos( $text , "&" );
        if ( $pos1 === false )
            return;

        $pos2 = strpos( $text , ";" , $pos1 + 1 );
        if ( $pos2 === false )
            return;

        $repl = substr( $text , $pos1 , $pos2 - $pos1 + 1 );
        //$entityCallback( $repl );

        // If there is text around the entity about to be recreated,
        // these need to be added as separated text nodes, around
        // the current node, before replacing the entire original
        // node by the new node.

        $doc = $node->ownerDocument;
        $name = substr( $text , $pos1 + 1 , $pos2 - $pos1 - 1 );

        $prefix = substr( $text , 0 , $pos1 );
        $suffix = substr( $text , $pos2 + 1 );
        $center = $doc->createEntityReference( $name );

        // DOMNode->replaceWith will cause double free()
        // errors and core dumps as late as PHP 8.1 on
        // Ubuntu. Code should only create nodes, never
        // delete or replace anything that may confuse the
        // old versions.

        if ( $prefix != "" )
            $node->before( $prefix );

        $node->before( $center );

        if ( $suffix != "" )
            $node->before( $suffix );

        $node->parentNode->removeChild( $node );

        if ( $center->nextSibling != null &&
             $center->nextSibling->nodeType == XML_TEXT_NODE )
            $this->parseNode( $center->nextSibling , $entityCallback );
    }

    private function loadFile( string $filename ) : DOMDocument
    {
        if ( ! file_exists( $filename ) )
            throw new Exception( "File not found." );
        $contents = file_get_contents( $filename );
        $ret = $this->loadText( $contents );
        return $ret;
    }

    private function loadText( string $text ) : DOMDocument // TODO DOMDocument|DOMFragment
    {
        $frag = false;
        $text = $this->encodeAmpersand( $text );

        $was = libxml_use_internal_errors( true );

        $doc = new DOMDocument( '1.0' , 'utf8' );
        $doc->preserveWhiteSpace = true;

        $ret = $doc->loadXML( $text );
        if ( ! $ret )
        {
            $frag = true;
            $text = '<frag>' . $text . '</frag>';
            $ret = $doc->loadXML( $text );
        }

        libxml_use_internal_errors( $was );
        $messages = libxml_get_errors();
        libxml_clear_errors();

        if ( ! $ret )
            throw new Exception( "Invalid well-balanced text." );

        foreach( $messages as $message )
            fwrite( STDERR , $messate );

        return $doc;
    }

    private function encodeAmpersand( string $text ) : string
    {
        $text = str_replace( "&" , "&amp;" , $text );

        // Revert numeric entities (&#nnn;), as
        // DOMDocument->createEntityReference cannot create
        // these, but are accepted when it is reading XML.

        while( true )
        {
            $pos = strpos( $text , '&amp;#' );
            if ( $pos === false )
                break;

            $end = strpos( $text , ';' , $pos + 5 );
            $len = $end - $pos + 1;
            $sub = substr( $text , $pos , $len );
            $rpl = str_replace( '&amp;' , '&' , $sub );
            $text = str_replace( $sub , $rpl , $text );
        }

        return $text;
    }

    private function revertAmpersands( DOMDocument $doc )
    {
        // There is some places where WBT machinery does
        // not (or does not can) replace textual '&amp;ent;'
        // into real XML_ENTITY_REF_NODE '&ent;' nodes. These
        // other nodes, that have pure text contents are:
        //
        // - 4 XML_CDATA_SECTION_NODE
        // - 7 XML_PI_NODE
        // - 8 XML_COMMENT_NODE

        $xpath = new DOMXPath( $doc );

        $nodes = $xpath->query( "//text()" );
        foreach( $nodes as $node )
            if ( $node->nodeType == XML_CDATA_SECTION_NODE )
                if ( strpos( $node->textContent , '&amp;' ) !== false )
                    $node->textContent = str_replace( '&amp;' , '&' , $node->textContent );

        $nodes = $xpath->query( "//processing-instruction()" );
        foreach( $nodes as $node )
            if ( strpos( $node->textContent , '&amp;' ) !== false )
                $node->textContent = str_replace( '&amp;' , '&' , $node->textContent );

        $nodes = $xpath->query( "//comment()" );
        foreach( $nodes as $node )
            if ( strpos( $node->textContent , '&amp;' ) !== false )
                $node->textContent = str_replace( '&amp;' , '&' , $node->textContent );
    }

    private function listTextNodes( DOMDocument $doc )
    {
        $ret = [];
        $xpath = new DOMXPath( $doc );

        // We ask for #text,
        // but we also receive
        // #cdata-section

        $texts = $xpath->query( "//text()" );
        foreach( $texts as $node )
            if ( $node->nodeType == XML_TEXT_NODE )
                $ret[] = $node;

        // Also, //text() does not find text nodes inside
        // attributes values. Sigh.

        $attrs = $xpath->query( "//@*" );
        foreach( $attrs as $attr )
            foreach( $attr->childNodes as $node )
                if ( strpos( $node->nodeValue , '&' ) !== false )
                    $ret[] = $node;

        return $ret;
    }
}