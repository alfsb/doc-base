<?php

require_once __DIR__ . "/path.php";
require_once __DIR__ . "/wbt.php";

class XmlAssembler
{
    private array $fileEntities = [];
    private array $pathEntities = [];

    public function assembly
    (
        string   $contents ,
        array    $baseDirs = [] ,
        array    $entities = [] ,
    )
        : DOMDocument
    {
        $temp = new XmlSimulateDebugFe();

        $temp->calculateFileEntities( $baseDirs ); // Old style, non-uniform schema, possible entity collisions
        $this->calculatePathEntities( $baseDirs ); // New style, relative path names, uniform schema, no entity collisions

        $callback = function( $name ) { return $this->expandEntityReference( $name ); };
        $contents = $this->stripDoctype( $contents );

        $doc = wbt_load_root( $contents , $callback );
        return $doc;
    }

    private function calculatePathEntities( array $baseDirs )
    {
        foreach( $baseDirs as $baseDir )
        {
            $skipLen = strlen( $baseDir );
            $files = path_list_sort_recurse( $baseDir );

            foreach( $files as $file )
            {
                $entName = substr( $file , $skipLen );
                $entName = str_replace( '\\' , '/' , $entName );
                if ( str_starts_with( $entName , '/' ) )
                    $entName = substr( $entName , 1 );

                $entName = "<?inc $entName ?>";
                $this->pathEntities[ $entName ] = $entName;
                $this->checkMixedCase( $entName );
            }
        }
echo "\n" . count( $this->pathEntities ) . "\n";
    }

    private function checkMixedCase( string|null $name )
    {
        //TODO
    }

    private function stripDoctype( string $text ) : string
    {
        $pos1 = strpos( $text , "<!DOCTYPE" );
        $pos2 = strpos( $text , "]>" );

        if ( $pos1 >= 0 && $pos2 >= 0 and $pos2 > $pos1 )
        {
            $head = substr( $text , 0 , $pos1 - 1 );
            $tail = substr( $text , $pos2 + 2);
            $text = $head . $tail;
        }

        return $text;
    }

    private function expandEntityReference( string $name ) : string|null
    {
        return null;
    }
}

// Temporary class, to simulate file-entities.php data, while
// developing XmlAssembler. After the build libxml/DTD entity
// assembly system is replaced by XmlAssembler/<?inc system,
// this class should be erased.

class XmlSimulateDebugFe
{
    public array $fileEntities = [];
    public array $pathEntities = [];

    //public array $fileEntitiesFallback = []; // ugly, TODO

    private string $outputFileDir = "";
    private string $outputListDir = "";

    public function calculateFileEntities( array $baseDirs )
    {
        $this->outputFileDir = realpain( __DIR__ . "/../../temp" , mkdir: true );
        $this->outputListDir = realpain( __DIR__ . "/../../temp/file-entities" , mkdir: true );

        $entGroup  = [];
        $entDirect = [];

        foreach( $baseDirs as $baseDir )
            $this->calculateFileEntitiesLang( $baseDir , $entGroup , $entDirect );

        // After all data is fully merged, time to create old/new style
        // file/path entities from file entities data.

        foreach( $entDirect as $name => $file )
        {
            $old = realpain( $file );
            $new = "<?inc $file ?>";
            $this->pushResult( $name , $old , $new );
        }
        ksort( $this->fileEntities );
        ksort( $this->pathEntities );

        foreach( $entGroup as $name => $map )
        {
            $fileLines = [];
            $pathLines = [];
            foreach( $map as $relative => $finalPath )
            {
                $fileLines[] = "&{$relative};";
                $pathLines[] = "<?inc $finalPath ?>";
            }
            sort( $fileLines );
            sort( $pathLines );
            $textFile = implode( '\n' , $fileLines );
            $textPath = implode( '\n' , $pathLines );
            $this->pushResult( $name , $textFile , $textPath );
        }

        $this->saveDebugResult();
    }

    private function calculateFileEntitiesLang( string $baseDir , array & $retGroup , array & $retDirect )
    {
        // Collect all files into grouped and direct mappings.
        // Map new directories into group file and schedule it
        // for further processing.

        $tempPrefix = "$baseDir/temp";

        $todoDirs = [ "$baseDir" ];
        while ( ( $dir = array_shift( $todoDirs ) ) != null )
        {
            $files = scandir( $dir );

            foreach( $files as $file )
            {
                $relPath = "$dir/$file";
                $isDir = is_dir( $relPath );

                if ( str_starts_with( $file , '.' ) )
                    continue;
                if ( str_starts_with( $relPath , $tempPrefix ) )
                    continue;

                // Directories becomes listings of files (only for reference).
                // Only XML files are mapped directly or in lists.

                if ( $isDir )
                {
                    $todoDirs[] = $relPath;
                }
                else
                    if ( str_ends_with( $file , ".xml" ) == false )
                        continue;

                $this->addFileEntityGroup( $baseDir , $relPath , $retGroup );
                $this->addFileEntityDirect( $baseDir , $relPath , $retDirect );
            }
        }
    }

    private function addFileEntityGroup( string $baseDir , string $languageFile , array & $retList )
    {
        if ( ! str_starts_with( $languageFile , "reference/" ) )
            return;

        // languageFile     lang/reference/dir/dir/file.xml
        // relativeFile          reference/dir/dir/file.xml
        // entName               reference.dir.entities.dir

        $entName = dirname( $languageFile );
        $entName = $this->calculateFileEntityName( $baseDir , $entName );
        if ( $entName == null )
            throw new Exception( "Should never happen!" );

        $relativeFile = substr( $languageFile , strlen( $baseDir ) );
        $relativeFile = ltrim( $relativeFile , '/' );

        // map[ entName ][ relativeFile ] = languagePath

        if ( ! isset( $retList[$entName] ) )
            $retList[$entName] = [];

        $retList[$entName][$relativeFile] = $languageFile;
    }

    private function addFileEntityDirect( string $baseDir , string $path , array & $retDirect )
    {
        $name = $this->calculateFileEntityName( $baseDir , $path );
        if ( $name == null )
            return;

        $refDir = "$baseDir/reference/";
        $isDir = is_dir( $path );
        $isFile = ! $isDir;

        $store = false;
        $store |= ( $isDir  && str_starts_with( $path , $refDir ) );
        $store |= ( $isFile && str_ends_with( $path , '.xml' ) );

        if ( $isDir )
            $path = "{$this->outputListDir}/{$name}.ent";

        if ( $isDir ) //TODO ugly, fix on main
            $path = str_replace( ".entities" , "" , $path );

        if ( $store )
            $retDirect[ $name ] = $path;
    }

    private function calculateFileEntityName( string $prefix , string $path ) : string|null
    {
        $isDir = is_dir( $path );
        $remove = strlen( $prefix );

        $name = trim( $path );
        $name = str_replace( '\\' , '.' , $name );
        $name = str_replace( '/' , '.' , $name );

        $name = substr( $name , $remove );
        if ( str_starts_with( $name , '.' ) )
            $name = substr( $name , 1 );

        if ( $isDir )
        {
            $parts = explode( '.' , $name );
            if ( count( $parts ) == 1 )
                return null;

            $last = array_pop( $parts );
            $parts[] = "entities";
            $parts[] = $last;
            $name = implode( '.' , $parts );
            return $name;
        }

        if ( str_ends_with( $name , ".xml") )
            $name = substr( $name , 0 , -4 );
        else
            $name = null;

        return $name;
    }

    private function pushResult( string $name , string $fileText , string $pathText )
    {
        if ( isset( $this->newEntities[ $name ] ) )
            print "FeCollision: $name\n";

        $this->fileEntities[ $name ] = $fileText;
        $this->pathEntities[ $name ] = $pathText;
    }

    private function saveDebugResult()
    {
        // Recreate file-entities.ent, for debug purposes

        $entFile = $this->outputFileDir . "/file-entities.ent";

        $lines = [];
        foreach( $this->fileEntities as $name => $text )
            if ( str_starts_with( $text , '/' ) )
                $lines[] = "<!ENTITY $name SYSTEM '$text'>";

        $contents = "<!-- DON'T TOUCH - AUTOGENERATED BY file-entities.php -->\n\n";
        $contents .= implode( "\n" , $lines );
        file_put_contents( $entFile , $contents );
    }
}