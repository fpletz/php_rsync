<?php
/**
 * !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!ATTANTION!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
 * This is a example for using the rsync extension
 * This example don't work with large directories and big files. 
 * There get alle changing content in the address space to send them over 
 * network, so if the data to transmit is to great to fit in the php max 
 * memory usage this client will be case an PHP error. 
 * 
 * Feel free to implement your own protocol using the librsync to generate 
 * signatur files and patch files.
 *
 * This server can only used to get request from client of changes in the 
 * directory on the server.
 * There generatt the patches of changed files, deleted files, new files and new 
 * directory. 
 */
if (!extension_loaded("rsync")) {
    echo "You need the rsync php extension loaded to use this!";
    exit;
}

// Config here the path where be provided to sync with the client.php
$syncpathes = array("testdir1" => "/testdir1", "testdir2" => "/testdir2");
// Set the Default path if the client.php don't send a basepath with the request.
$default = "testdir1";

/**
 * The rsync example Client Class
 */
class rsyncServer
{
    /**
     * Basis Path at the rsync php extension Server 
     * 
     * @var string
     */
    public $basepath;
    
    /**
     * Local directory path to sync
     * 
     * @var string 
     */
    public $localpath;
    
    /**
     * Sync direction f for syncing changes from Client to Server and 
     * b for syncing changes from Server to Client.
     * 
     * @var string
     */
    public $direction = 'f';
    
    /**
     * list of all entries and subentries of the local directory.
     * 
     * @var array
     */
    public $structure = array();
    
    public $result = array();

    /**
     * Constructor of the Client.
     * 
     * @param string $localpath Local directory to sync
     * @param string $direction Direction to sync 
     *                          f = server to client
     *                          b = client to server
     */
    public function __construct($localpath, $direction)
    {
        $this->localpath = $localpath;
        $this->getLocalStructur($localpath);
        $this->direction = $direction;
    }

    /**
     * 
     * @param type $remoteStructure
     * @param type $signatures 
     */
    public function serverToClientSync($remoteStructure, $signatures)
    {
        foreach ($remoteStructure as $name => $data) {
            if (array_key_exists($name, $this->structure)) {
                $patch = $this->createPatch($name, $signatures[$name]);
                if ($patch === false) return json_encode("ERROR");
                $this->result['changes'][$name] = $this->structure[$name];
                $this->result['changes'][$name]['patch'] = $patch;
                $this->result['changes'][$name]['changetype'] = 'patch';
	    } else {
                $this->result['changes'][$name] = $this->structure[$name];
                $this->result['changes'][$name]['changetype'] = 'delete';
            }
        }
        foreach($this->structure as $name => $data) {
            if (!array_key_exists($name, $remoteStructure)) {
                $this->result['changes'][$name] = $data;
                if ($data['type'] == 'dir') {
                    $this->result['changes'][$name]['changetype'] = 'newDir';
                } else {
                    $this->result['changes'][$name]['changetype'] = 'newFile';
                    $this->result['changes'][$name]['content'] = 
                            file_get_contents($this->localpath.
                                    DIRECTORY_SEPARATOR.$name);
                }
            }
        }
        return json_encode($this->result);
    }
    
    /**
     *
     * @param type $name
     * @param type $signature
     * @return type 
     */
    public function createPatch($name, $signature) {
        $patchfile = tempnam(sys_get_temp_dir(), 'patch');
        $sighandle = fopen('data://text/plain;base64,'.
                base64_encode($signature), 'rb');
        $ret = rsync_generate_delta($sighandle, 
                $this->localpath.DIRECTORY_SEPARATOR.$name, $patchfile);
        fclose($sighandle);
        if ($ret != RSYNC_DONE) {
            return false;
        }
        $patch = file_get_contents($patchfile);
        unlink($patchfile);
        return $patch;
    }


    /**
     * Get the Local Directory Structure to check against the remote.
     * This Method is working recursive to step deeper in the directory.
     *
     * @param string $dir    Aktual working directory
     * @param string $prefix Prefix to make relative path to the initial 
     *                       directory
     */
    public function getLocalStructur($dir, $prefix = '')
    {
        $actualDirContent = scandir($dir);
        foreach( $actualDirContent as $dentry) {
            if ($dentry != '.' || $dentry != '..') {
                $type = filetype($dir."/".$dentry);
                if ($type != 'dir' && $type != 'file') continue;
                $stats = stat($dir."/".$dentry);
                if ($stats === FALSE) {
                    throw new Exception("Filestats for ".$dir."/".$dentry.
                            " ist not readable!", 9);
                }
                $this->structure[$prefix.'/'.$dentry] = array(
                    'name' => $prefix.'/'.$dentry,
                    'type' => $type, 'rights' => $stats['mode'],
                    'mtime' => $stats['mtime'], 'uid' => $stats['uid'],
                    'gid' => $stats['gid']);
                if ($type == 'dir') {
                    $this->getLocalStructur($dir.'/'.$dentry, 
                            $prefix.'/'.$dentry);
                }
            }
        }
    }
}

// check if the Request Parameter step is given
if (!isset($_REQUEST['step'])) {
    echo json_encode("ERROR");
}

// Check if Basepath parameter is send from the client.
// If not use the default from configuration (see the begin of this file).
if (isset($_REQUEST['basepath'])) {
    if (!in_array($_REQUEST['basepath'], $syncpathes)) {
        echo json_encode("ERROR");
        exit;
    }
    $localpath = $_REQUEST['basepath'];
} else {
    $localpath = $syncpathes[$default];
}

// Check if the sync direction is given and has the right parameter.
if ($_REQUEST['direction'] != 'f' && $_REQUEST['direction'] != 'b') {
    echo json_encode("ERROR");
    exit;
}

$direction = $_REQUEST['direction'];


if (!isset($_REQUEST['filelist']) && empty($_REQUEST['filelist'])) {
    echo json_encode("ERROR");
    exit;
}
// decode the json encoded filelist parameter
$remoteStructure = json_decode($_REQUEST['filelist']);

// Get the signatures from client if direction is serverToClient (f)
$signatures = null;
if ($direction == 'f') {
    if (!isset($_REQUEST['signatures'])) {
        echo json_encode("ERROR");
        exit;
    }
    $signatures = json_decode($_REQUEST['signatures']);
} else {
    // @TODO Client to Server sync is not implemented.
    echo json_encode("ERROR");
    exit;
}

// Initialize the Server Class.
try {
    $server = new rsyncServer($localpath, $direction);
} catch (Exception $e) {
    echo json_encode("ERROR");
    exit;
}

// Switch between the diffrent Steps of Syncing (Server to Client sync has only one step).
switch ($_REQUEST['step']) {
    case '1':
	if ($direction == 'f') {
            echo $server->serverToClientSync($remoteStructure, $signatures);
            break;
        } else {
            // @TODO Client to Server Sync is not implemented.
            break;
	}
	break;
    case '2':
        // @TODO Client to Server sync is not impelemented.
        echo json_encode("ERROR");
        break;
    default:
        // Unknowed Step given!
        echo json_encode("ERROR");
        break;
}
