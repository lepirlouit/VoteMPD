<?php

require("mpd.php");
require("settings.php");

/*
---------------------------------------------------------------------------
------------------------------AJAX STUFF-----------------------------------
---------------------------------------------------------------------------
*/

//output an ajax error
function doError($e) {
    $a = array();
    $a["status"] = "error";
    $a["reason"] = $e;
    echo json_encode($a);
    die();
}

//output ajax
function doOutput($content,$action) {
    $a = array();
    $a["status"] = "success";
    $a["action"] = $action;
    $a["content"] = $content;
    echo json_encode($a);
    die();
}

/*
---------------------------------------------------------------------------
------------------------------DAEMON STUFF---------------------------------
---------------------------------------------------------------------------
*/

//first daemon call
function daemonCallInit() {
    $mpd = new MPD();
    $mpd->cmd("stop");
    $mpd->cmd("clear");
    addOneFileToMpdQueue(true);
}

//take first file from highscore and add it to mpd Queue
function addOneFileToMpdQueue($first=false) {
    $mpd = new MPD();
    $hn = getNextsongInHighscore();
    
    if($hn!==null) {
        $path = getFilepathForFileid($hn->id);
        $mpd->cmd('add "'.$path.'"');
        $state = getMpdValue("status","state");
        if($state != "play") {
            $mpd->cmd("play");
        }
        if($first) {
            $timeToAction = intval($hn->length)-4;
        } else {
            $timeTotal = getMpdValue("currentsong","Time");
            $timeCurrent = getMpdCurrentTime();
            $timeToAction = intval($hn->length)+(intval($timeTotal)-intval($timeCurrent))-4;
        }
        Tasker::add($timeToAction,'addOneFileToMpdQueue',array());
        
        $stmt = $GLOBALS["db"]->prepare("UPDATE votes set played=1 WHERE fileid=:fileid");
        if(!$stmt->execute(array(":fileid" => $hn->id))) {
            echo "error";
        }
        
        $stmt = $GLOBALS["db"]->prepare("INSERT INTO playlog (fileid,date) VALUES (:fileid,NOW())");
        if(!$stmt->execute(array(":fileid" => $hn->id))) {
            echo "error";
        }
    } else {
        Tasker::add(5,'daemonCallInit',array());
    }
}

/*
---------------------------------------------------------------------------
------------------------------MPD STUFF------------------------------------
---------------------------------------------------------------------------
*/

//get one value from mpd server
function getMpdValue($function,$item) {
    $mpd = new MPD();
    $r = $mpd->cmd($function);
    if(false===strpos($r,$item.": ")) {
        return false;
    } else {
        $tmp = explode("\n",$r);
        $path = false;
        foreach($tmp as $t) {
            $tmpar = explode(": ",$t);
            if(count($tmpar)===2 && $tmpar[0]==$item) {
                return $tmpar[1];
            }
        }
        return false;
    }
}

//get current time position in song from mpd server
function getMpdCurrentTime() {
    $time = false;
    $time = getMpdValue("status","time");
    if($time!==false) $time = explode(":",getMpdValue("status","time"))[0];
    return $time;
}

//get current song from mpd server
function getMpdCurrentSong() {
    $path = getMpdValue("currentsong","file");
    if($path===false) $fileinfos = false;
    else $fileinfos = getFileinfosforfilepath($path);
    $state = getMpdValue("status","state");
    return array("state"=>$state,"time"=>getMpdCurrentTime(),"fileinfos"=>$fileinfos);
}

/*
---------------------------------------------------------------------------
------------------------------HELPER FUNCTIONS-----------------------------
---------------------------------------------------------------------------
*/

// filepath => infos
function getFileinfosforfilepath($path) {
    $folders = explode("/",dirname($path));
    $curDir = -1;
    foreach($folders as $f) {
        $stmt = $GLOBALS["db"]->prepare("SELECT id,picture FROM folders WHERE parentid=:p AND foldername=:f");
        if($stmt->execute(array(":p" => $curDir,":f" => $f))) {
            $row = $stmt->fetchObject();
            $curDir=$row->id;
        } else doError("getFileinfosforfilepath db query failed");
    }
    
    $pic=false;
    if($curDir!=-1 && isset($row->picture)) {
        $pic = true;
    }
    
    $stmt = $GLOBALS["db"]->prepare("SELECT * FROM files WHERE folderid=:folderid AND filename=:filename");
    if($stmt->execute(array(":folderid" => $curDir,":filename" => basename($path)))) {
        $row = $stmt->fetchObject();
        $row->picture = $pic;
        return $row;
    } else doError("getFileinfosforfilepath db query failed2");
    return false;
}

// folderpath => infos
function getFolderpathForFolderid($id) {
    $currentfolder = $id;
    $folders = array();
    
    while($currentfolder!=-1) {
        $folder = getFolder($currentfolder);
        $currentfolder = $folder -> parentid;
        $folders[] = $folder -> foldername;
    }
    $path = "";
    if(count($folders)>0) {
        $folders = array_reverse($folders);
        $path = implode("/",$folders)."/";
    }
    return "/".$path;
}

// fileid => filepath
function getFilepathForFileid($id) {
    $file = getFile($id);
    $currentfolder = $file -> folderid;
    $folders = array();
    
    while($currentfolder!=-1) {
        $folder = getFolder($currentfolder);
        $currentfolder = $folder -> parentid;
        $folders[] = $folder -> foldername;
    }
    $path = "";
    if(count($folders)>0) {
        $folders = array_reverse($folders);
        $path = implode("/",$folders)."/";
    }
    return $path.$file->filename;
}

//get a folder
function getFolder($id) {
    $stmt = $GLOBALS["db"]->prepare("SELECT id,parentid,foldername FROM folders WHERE id=:id");
    if($stmt->execute(array(":id" => $id))) {
        $row = $stmt->fetchObject();
        return $row;
    } else doError("getFolder db query failed");
}

//get a folder picture
function getFolderPic($id) {
    $stmt = $GLOBALS["db"]->prepare("SELECT * FROM folders WHERE id=:id");
    if($stmt->execute(array(":id" => $id))) {
        $row = $stmt->fetchObject();
        return $row;
    } else doError("getFolder db query failed");
}

//get a file
function getFile($id) {
    $stmt = $GLOBALS["db"]->prepare("SELECT * FROM files WHERE id=:id");
    if($stmt->execute(array(":id" => $id))) {
        $row = $stmt->fetchObject();
        return $row;
    } else doError("getFile db query failed");
}

/*
---------------------------------------------------------------------------
--------------------------AJAX CALLS NOT IN ACCORDION ---------------------
---------------------------------------------------------------------------
*/

//get the next song in highscore
function getNextsongInHighscore() {
    $tmp = doShowhighscore();
    if(!($tmp===false || $tmp===null || count($tmp)==0)) {
        //return first highscore item
        return $tmp[0];
    } else {
        //no first highscore item availiable, so pick least played from default playlist
        return null; //todo remove
        //todo current id save in daemon variable, or database options table
        $subFiles = array();
        $stmt = $GLOBALS["db"]->prepare("
            SELECT 
                id,
                filename,
                artist,
                title,
                length,
                size 
            FROM 
                playlistitems 
            INNER JOIN 
                files on(files.id=playlistitems.fileid) 
            WHERE 
                fileid IS NOT NULL AND 
                playlistname=:name");
        if($stmt->execute(array(":name" => $GLOBALS["defaultplaylist"]))) {
            if($row = $stmt->fetchObject()) {
                return $row; //todo check for correct format
            } else return false;
        } else return false;
    }
}

//vote for a song
function doVote($ip,$id) {
    $stmt = $GLOBALS["db"]->prepare("SELECT votes.date,files.* FROM votes INNER JOIN files on files.id=votes.fileid WHERE votes.ip=:ip AND votes.played=0 AND votes.fileid=:fileid");
    $tmp = array();
    $exists = false;
    if($stmt->execute(array(":ip" => $ip,":fileid" => $id))) {
        if ($row = $stmt->fetchObject()) {
            $exists = true;
        }
    } else doError("Getmyvotes db query failed");
    
    if($exists) {
        return false;
    } else {
        $stmt = $GLOBALS["db"]->prepare("INSERT INTO votes (fileid,ip,date) VALUES (:fid,:ip,NOW())");
        return ($stmt->execute(array(":fid" => $id,":ip"=>$ip)));
    }
}

/*
---------------------------------------------------------------------------
------------------------------ACCORDION STUFF------------------------------
---------------------------------------------------------------------------
*/

//return votes from this ip
function doGetmyvotes() {
    $stmt = $GLOBALS["db"]->prepare("SELECT votes.date,files.* FROM votes INNER JOIN files on files.id=votes.fileid WHERE votes.ip=:ip AND votes.played=0 ORDER BY date ASC");
    $tmp = array();
    if($stmt->execute(array(":ip" => $_SERVER['REMOTE_ADDR']))) {
        while ($row = $stmt->fetchObject()) {
            $tmp[] = $row;
        }
        return $tmp;
    } else doError("Getmyvotes db query failed");
}

//return highscore
function doShowhighscore() {
    $stmt = $GLOBALS["db"]->prepare("
    
        SELECT 
            files.*,votes.date,COUNT(*) as anzahl 
        FROM 
            (SELECT * FROM votes ORDER BY date ASC) as votes
        INNER JOIN 
            files on files.id=votes.fileid 
        WHERE 
            votes.played=0
        GROUP BY
            votes.fileid
        ORDER BY
            anzahl DESC,
			date ASC");
    $tmp = array();
    if($stmt->execute()) {
        while ($row = $stmt->fetchObject()) {
            $tmp[] = $row;
        }
        return $tmp;
    } else doError("Highscore db query failed");
}

//search for keywords
function doSearch($keyword) {
    $stmt = $GLOBALS["db"]->prepare("SELECT * FROM files WHERE filename LIKE :d OR artist LIKE :d OR title LIKE :d OR album LIKE :d");
    $tmp = array();
    if($stmt->execute(array(":d" => "%".$keyword."%"))) {
        while ($row = $stmt->fetchObject()) {
            $tmp[] = $row;
        }
        
        for($i=0;$i<count($tmp);$i++) {
            $stmt = $GLOBALS["db"]->prepare("SELECT date FROM votes WHERE fileid =:fid AND ip=:ip ORDER BY date DESC LIMIT 1");
            $dateLastVote=null;
            if($stmt->execute(array(":fid" => $tmp[$i]->id,":ip" => $_SERVER['REMOTE_ADDR']))) {
                if ($row = $stmt->fetchObject()) {
                    $dateLastVote = $row->date;
                }
            }
            
            $stmt = $GLOBALS["db"]->prepare("SELECT date FROM playlog WHERE fileid =:fid ORDER BY date DESC LIMIT 1");
            $dateLastPlay=null;
            if($stmt->execute(array(":fid" => $tmp[$i]->id))) {
                if ($row = $stmt->fetchObject()) {
                    $dateLastPlay = $row->date;
                }
            }
            
            if($dateLastVote===null && $dateLastPlay===null) {
                $tmp[$i]->alreadyVoted = false;
            } elseif($dateLastVote===null && $dateLastPlay!==null) {
                $tmp[$i]->alreadyVoted = false;
            } elseif($dateLastVote!==null && $dateLastPlay===null) {
                $tmp[$i]->alreadyVoted = true;
            } elseif($dateLastVote!==null && $dateLastPlay!==null) {
                $tmp[$i]->alreadyVoted = ($dateLastVote>$dateLastPlay);
            }
        }
        
        return $tmp;
    } else doError("Search db query failed");
}

//browse folders
function getBrowseFolder($id) {
    if($id==-1) {
        $thisFolder = "ROOT";
    } else {
        $thisFolder = getFolder($id);
        if($thisFolder===false) doError("getBrowseFolder Folder not found");
    }

    $subFolders = array();
    $subFiles = array();

    $stmt = $GLOBALS["db"]->prepare("SELECT id,foldername FROM folders WHERE parentid=:id");
    if($stmt->execute(array(":id" => $id))) {
        while ($row = $stmt->fetchObject()) {
            $subFolders[] = $row;
        }
    } else doError("getBrowseFolder (getSubFolders) db query failed");    
    
    $stmt = $GLOBALS["db"]->prepare("SELECT id,filename,artist,title,length,size FROM files WHERE folderid=:id");
    if($stmt->execute(array(":id" => $id))) {
        while ($row = $stmt->fetchObject()) {
            $subFiles[] = $row;
        }
        
        for($i=0;$i<count($subFiles);$i++) {
            $stmt = $GLOBALS["db"]->prepare("SELECT date FROM votes WHERE fileid =:fid AND ip=:ip ORDER BY date DESC LIMIT 1");
            $dateLastVote=null;
            if($stmt->execute(array(":fid" => $subFiles[$i]->id,":ip" => $_SERVER['REMOTE_ADDR']))) {
                if ($row = $stmt->fetchObject()) {
                    $dateLastVote = $row->date;
                }
            }
            
            $stmt = $GLOBALS["db"]->prepare("SELECT date FROM playlog WHERE fileid =:fid ORDER BY date DESC LIMIT 1");
            $dateLastPlay=null;
            if($stmt->execute(array(":fid" => $subFiles[$i]->id))) {
                if ($row = $stmt->fetchObject()) {
                    $dateLastPlay = $row->date;
                }
            }
            
            if($dateLastVote===null && $dateLastPlay===null) {
                $subFiles[$i]->alreadyVoted = false;
            } elseif($dateLastVote===null && $dateLastPlay!==null) {
                $subFiles[$i]->alreadyVoted = false;
            } elseif($dateLastVote!==null && $dateLastPlay===null) {
                $subFiles[$i]->alreadyVoted = true;
            } elseif($dateLastVote!==null && $dateLastPlay!==null) {
                $subFiles[$i]->alreadyVoted = ($dateLastVote>$dateLastPlay);
            }
        }
        
    } else doError("getBrowseFolder (getSubFiles) db query failed");
    return ["path"=>getFolderpathForFolderid($id),"this"=>$thisFolder,"folders"=>$subFolders,"files"=>$subFiles];
}

//browse artists
function getBrowseArtist($name) {
    if($name=="ROOT") {
        $artists = array();
        $stmt = $GLOBALS["db"]->prepare("SELECT artist FROM files WHERE artist!='' AND artist!=' ' GROUP BY artist");
        if($stmt->execute(array(":name" => $name))) {
            while ($row = $stmt->fetchObject()) {
                $subFiles[] = $row;
            }
    
        } else doError("getBrowseArtist (getArtists) db query failed");
        return ["name"=>$name,"artists"=>$subFiles];
    }

    $subFiles = array();
    
    $stmt = $GLOBALS["db"]->prepare("SELECT id,filename,artist,title,length,size FROM files WHERE artist=:name");
    if($stmt->execute(array(":name" => $name))) {
        while ($row = $stmt->fetchObject()) {
            $subFiles[] = $row;
        }
        
        for($i=0;$i<count($subFiles);$i++) {
            $stmt = $GLOBALS["db"]->prepare("SELECT date FROM votes WHERE fileid =:fid AND ip=:ip ORDER BY date DESC LIMIT 1");
            $dateLastVote=null;
            if($stmt->execute(array(":fid" => $subFiles[$i]->id,":ip" => $_SERVER['REMOTE_ADDR']))) {
                if ($row = $stmt->fetchObject()) {
                    $dateLastVote = $row->date;
                }
            }
            
            $stmt = $GLOBALS["db"]->prepare("SELECT date FROM playlog WHERE fileid =:fid ORDER BY date DESC LIMIT 1");
            $dateLastPlay=null;
            if($stmt->execute(array(":fid" => $subFiles[$i]->id))) {
                if ($row = $stmt->fetchObject()) {
                    $dateLastPlay = $row->date;
                }
            }
            
            if($dateLastVote===null && $dateLastPlay===null) {
                $subFiles[$i]->alreadyVoted = false;
            } elseif($dateLastVote===null && $dateLastPlay!==null) {
                $subFiles[$i]->alreadyVoted = false;
            } elseif($dateLastVote!==null && $dateLastPlay===null) {
                $subFiles[$i]->alreadyVoted = true;
            } elseif($dateLastVote!==null && $dateLastPlay!==null) {
                $subFiles[$i]->alreadyVoted = ($dateLastVote>$dateLastPlay);
            }
        }
        
    } else doError("getBrowseArtist (getSubFiles) db query failed");
    return ["name"=>$name,"files"=>$subFiles];
}

//browse albums
function getBrowseAlbum($name) {
    if($name=="ROOT") {
        $artists = array();
        $stmt = $GLOBALS["db"]->prepare("SELECT album FROM files WHERE album!='' AND album!=' ' GROUP BY album");
        if($stmt->execute(array(":name" => $name))) {
            while ($row = $stmt->fetchObject()) {
                $subFiles[] = $row;
            }
    
        } else doError("getBrowseAlbum (getAlbum) db query failed");
        return ["name"=>$name,"albums"=>$subFiles];
    }

    $subFiles = array();
    
    $stmt = $GLOBALS["db"]->prepare("SELECT id,filename,artist,title,length,size FROM files WHERE album=:name");
    if($stmt->execute(array(":name" => $name))) {
        while ($row = $stmt->fetchObject()) {
            $subFiles[] = $row;
        }
        
        for($i=0;$i<count($subFiles);$i++) {
            $stmt = $GLOBALS["db"]->prepare("SELECT date FROM votes WHERE fileid =:fid AND ip=:ip ORDER BY date DESC LIMIT 1");
            $dateLastVote=null;
            if($stmt->execute(array(":fid" => $subFiles[$i]->id,":ip" => $_SERVER['REMOTE_ADDR']))) {
                if ($row = $stmt->fetchObject()) {
                    $dateLastVote = $row->date;
                }
            }
            
            $stmt = $GLOBALS["db"]->prepare("SELECT date FROM playlog WHERE fileid =:fid ORDER BY date DESC LIMIT 1");
            $dateLastPlay=null;
            if($stmt->execute(array(":fid" => $subFiles[$i]->id))) {
                if ($row = $stmt->fetchObject()) {
                    $dateLastPlay = $row->date;
                }
            }
            
            if($dateLastVote===null && $dateLastPlay===null) {
                $subFiles[$i]->alreadyVoted = false;
            } elseif($dateLastVote===null && $dateLastPlay!==null) {
                $subFiles[$i]->alreadyVoted = false;
            } elseif($dateLastVote!==null && $dateLastPlay===null) {
                $subFiles[$i]->alreadyVoted = true;
            } elseif($dateLastVote!==null && $dateLastPlay!==null) {
                $subFiles[$i]->alreadyVoted = ($dateLastVote>$dateLastPlay);
            }
        }
        
    } else doError("getBrowseAlbum (getSubFiles) db query failed");
    return ["name"=>$name,"files"=>$subFiles];
}

//browse playlists
function getBrowsePlaylist($name) {
    if($name=="ROOT") {
        $playlists = array();
        $stmt = $GLOBALS["db"]->prepare("SELECT playlistname FROM playlistitems WHERE playlistname!='' AND playlistname!=' ' GROUP BY playlistname");
        if($stmt->execute(array(":name" => $name))) {
            while ($row = $stmt->fetchObject()) {
                $subFiles[] = $row;
            }
    
        } else doError("getBrowsePlaylist (getPlaylist) db query failed");
        return ["name"=>$name,"playlists"=>$subFiles];
    }

    $subFiles = array();
    
    $stmt = $GLOBALS["db"]->prepare("SELECT id,filename,artist,title,length,size from playlistitems INNER JOIN files on(files.id=playlistitems.fileid) WHERE fileid IS NOT NULL AND playlistname=:name");
    if($stmt->execute(array(":name" => $name))) {
        while ($row = $stmt->fetchObject()) {
            $subFiles[] = $row;
        }
        
        for($i=0;$i<count($subFiles);$i++) {
            $stmt = $GLOBALS["db"]->prepare("SELECT date FROM votes WHERE fileid =:fid AND ip=:ip ORDER BY date DESC LIMIT 1");
            $dateLastVote=null;
            if($stmt->execute(array(":fid" => $subFiles[$i]->id,":ip" => $_SERVER['REMOTE_ADDR']))) {
                if ($row = $stmt->fetchObject()) {
                    $dateLastVote = $row->date;
                }
            }
            
            $stmt = $GLOBALS["db"]->prepare("SELECT date FROM playlog WHERE fileid =:fid ORDER BY date DESC LIMIT 1");
            $dateLastPlay=null;
            if($stmt->execute(array(":fid" => $subFiles[$i]->id))) {
                if ($row = $stmt->fetchObject()) {
                    $dateLastPlay = $row->date;
                }
            }
            
            if($dateLastVote===null && $dateLastPlay===null) {
                $subFiles[$i]->alreadyVoted = false;
            } elseif($dateLastVote===null && $dateLastPlay!==null) {
                $subFiles[$i]->alreadyVoted = false;
            } elseif($dateLastVote!==null && $dateLastPlay===null) {
                $subFiles[$i]->alreadyVoted = true;
            } elseif($dateLastVote!==null && $dateLastPlay!==null) {
                $subFiles[$i]->alreadyVoted = ($dateLastVote>$dateLastPlay);
            }
        }
        
    } else doError("getBrowsePlaylist (getSubFiles) db query failed");
    return ["name"=>$name,"files"=>$subFiles];
}

//get files often played in playlists
function getBrowseOftenPlaylist() {
    $subFiles = array();
    
    $stmt = $GLOBALS["db"]->prepare("SELECT id,filename,artist,title,length,size, COUNT(*) as count from playlistitems INNER JOIN files on(files.id=playlistitems.fileid) WHERE fileid IS NOT NULL GROUP BY id ORDER BY count DESC");
    if($stmt->execute()) {
        while ($row = $stmt->fetchObject()) {
            $subFiles[] = $row;
        }
        
        for($i=0;$i<count($subFiles);$i++) {
            $stmt = $GLOBALS["db"]->prepare("SELECT date FROM votes WHERE fileid =:fid AND ip=:ip ORDER BY date DESC LIMIT 1");
            $dateLastVote=null;
            if($stmt->execute(array(":fid" => $subFiles[$i]->id,":ip" => $_SERVER['REMOTE_ADDR']))) {
                if ($row = $stmt->fetchObject()) {
                    $dateLastVote = $row->date;
                }
            }
            
            $stmt = $GLOBALS["db"]->prepare("SELECT date FROM playlog WHERE fileid =:fid ORDER BY date DESC LIMIT 1");
            $dateLastPlay=null;
            if($stmt->execute(array(":fid" => $subFiles[$i]->id))) {
                if ($row = $stmt->fetchObject()) {
                    $dateLastPlay = $row->date;
                }
            }
            
            if($dateLastVote===null && $dateLastPlay===null) {
                $subFiles[$i]->alreadyVoted = false;
            } elseif($dateLastVote===null && $dateLastPlay!==null) {
                $subFiles[$i]->alreadyVoted = false;
            } elseif($dateLastVote!==null && $dateLastPlay===null) {
                $subFiles[$i]->alreadyVoted = true;
            } elseif($dateLastVote!==null && $dateLastPlay!==null) {
                $subFiles[$i]->alreadyVoted = ($dateLastVote>$dateLastPlay);
            }
        }
        
    } else doError("getBrowseOftenPlaylist (getSubFiles) db query failed");
    return ["files"=>$subFiles];
}

//get files often voted
function getBrowseOftenVote() {
    $subFiles = array();
    
    $stmt = $GLOBALS["db"]->prepare("SELECT files.id,filename,artist,title,length,size, COUNT(*) as count from votes INNER JOIN files on(files.id=votes.fileid) GROUP BY files.id ORDER BY count DESC");
    if($stmt->execute()) {
        while ($row = $stmt->fetchObject()) {
            $subFiles[] = $row;
        }
        
        for($i=0;$i<count($subFiles);$i++) {
            $stmt = $GLOBALS["db"]->prepare("SELECT date FROM votes WHERE fileid =:fid AND ip=:ip ORDER BY date DESC LIMIT 1");
            $dateLastVote=null;
            if($stmt->execute(array(":fid" => $subFiles[$i]->id,":ip" => $_SERVER['REMOTE_ADDR']))) {
                if ($row = $stmt->fetchObject()) {
                    $dateLastVote = $row->date;
                }
            }
            
            $stmt = $GLOBALS["db"]->prepare("SELECT date FROM playlog WHERE fileid =:fid ORDER BY date DESC LIMIT 1");
            $dateLastPlay=null;
            if($stmt->execute(array(":fid" => $subFiles[$i]->id))) {
                if ($row = $stmt->fetchObject()) {
                    $dateLastPlay = $row->date;
                }
            }
            
            if($dateLastVote===null && $dateLastPlay===null) {
                $subFiles[$i]->alreadyVoted = false;
            } elseif($dateLastVote===null && $dateLastPlay!==null) {
                $subFiles[$i]->alreadyVoted = false;
            } elseif($dateLastVote!==null && $dateLastPlay===null) {
                $subFiles[$i]->alreadyVoted = true;
            } elseif($dateLastVote!==null && $dateLastPlay!==null) {
                $subFiles[$i]->alreadyVoted = ($dateLastVote>$dateLastPlay);
            }
        }
        
    } else doError("getBrowseOftenPlaylist (getSubFiles) db query failed");
    return ["files"=>$subFiles];
}

?>