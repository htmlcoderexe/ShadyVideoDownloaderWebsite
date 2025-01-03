<?php
session_start();
setlocale(LC_ALL,'C.UTF-8');
class YTDLP
{
    public static $config_file=".dlconfig";
    
    public static $yt_dl_base='yt-dlp';
    public static $output_format ="%(title)s.%(ext)s";
    public static $cache_dir=".cache";
    public static $removal_options=' --replace-in-metadata title "[\U00010000-\U0010ffff]" "" --replace-in-metadata title "&" "_" --replace-in-metadata title "," "_" --replace-in-metadata title "=" "_" --replace-in-metadata title " " "_"';
    public static $yt_dl_temp_path="tmp";
    public static $dl_dir_common='mp4';
    public static $dl_dir_mp3='mp3';
    public static $yt_dl_format_1080p=' -S res:1080,fps,+codec:avc:m4a ';
    public static $yt_dl_pls_no_sponsor=' --sponsorblock-remove sponsor --sponsorblock-mark all,-filler ';
    public static $youtubedl_video_standard;
    public static $youtubemp3=" -x --audio-format mp3 ";
    public static $jobpath="jobs";
    public static $args=[];
    
    public static $erase_pass="";
    public static $job_ttl=120;
    
    public static $user="";
    public static $passhash="";
    
    public static $DOWNLOADER=[];
    
    static $jobindex=0;
    
    static function LoadConfig()
    {
        if(!file_exists(self::$config_file))
        {
            return false;
        }
        else
        {
            $config=json_decode(file_get_contents(".dlconfig"),true);
            self::$dl_dir_common=$config['videopath'];
            self::$dl_dir_mp3=$config['musicpath'];
            self::$yt_dl_temp_path=$config['temppath'];
            self::$jobpath=$config['jobpath'];
            self::$user=$config['user'];
            self::$passhash=$config['pass'];
            self::$cache_dir=$config['cachepath'];
            self::$job_ttl=$config['job_ttl'];
            self::$erase_pass=$config['erase_pass'];
        }
        return true;
    }
    static function SaveConfig()
    {
        $config=[
            "videopath"=>self::$dl_dir_common,
            "musicpath"=>self::$dl_dir_mp3,
            "temppath"=>self::$yt_dl_temp_path,
            "jobpath"=>self::$jobpath,
            "cachepath"=>self::$cache_dir,
            "user"=>self::$user,
            "pass"=>self::$passhash,
            "erase_pass"=>self::$erase_pass,
            "job_ttl"=>self::$job_ttl
        ];
        file_put_contents(".dlconfig", json_encode($config));
    }
    
    static function SaveDownloader()
    {
        $jobs=[];
        foreach(self::$DOWNLOADER as $jobinfo)
        {
            file_put_contents(self::$jobpath."/".$jobinfo['jobid'].".job",json_encode($jobinfo));
            $jobs[]=$jobinfo['jobid'];
        }
        setcookie("jobs",json_encode($jobs),time()+60*60*24*30);
    }
    
    static function LoadJob($jobid)
    {
        return json_decode(file_get_contents(self::$jobpath."/".basename($jobid).".job"),true);
    }
    
    static function LoadDownloader()
    {
        if(!isset($_COOKIE['jobs']))
        {
            self::$DOWNLOADER=[];
            setcookie("jobs",json_encode([]),time()+60*60*24*30);
        }
        $joblist=json_decode(stripslashes($_COOKIE['jobs']),true);
        foreach($joblist as $job)
        {
            if(file_exists(self::$jobpath."/".basename($job).".job"))
            {
                self::$DOWNLOADER[]=self::LoadJob($job);
            }
        }
    }
    
    static function FetchStaleJobs()
    {
        $worker=new FilesystemIterator(self::$jobpath);
        $cutoff=time()-(self::$job_ttl*60);
        foreach($worker as $job)
        {
            $fn=$job->getFilename();
            if(substr($fn,-4)!==".job")
            {
                if($job->getCTime()<$cutoff)
                {
                    self::NukeJob($fn);
                }
                
            }
        }
    }
    
    static function NukeJob($jobid)
    {
        @unlink(self::$jobpath . "/" . $jobid);
        @unlink(self::$jobpath . "/" . $jobid.".job");
        if(file_exists(self::$dl_dir_common."/".$jobid))
        {
            $remover=new FilesystemIterator(self::$dl_dir_common."/".$jobid);
            foreach($remover as $item)
            {
                @unlink(self::$dl_dir_common."/".$jobid."/".$item->getFilename());
            }
        @rmdir(self::$dl_dir_common."/".$jobid);
        @rmdir(self::$yt_dl_temp_path."/".self::$dl_dir_common."/".$jobid);
        }
        if(file_exists(self::$dl_dir_mp3."/".$jobid))
        {
            $remover=new FilesystemIterator(self::$dl_dir_mp3."/".$jobid);
            foreach($remover as $item)
            {
                @unlink(self::$dl_dir_mp3."/".$jobid."/".$item->getFilename());
            }
        @rmdir(self::$dl_dir_mp3."/".$jobid);
        @rmdir(self::$yt_dl_temp_path."/".self::$dl_dir_mp3."/".$jobid);
        }
    }
    
    static function SetupDir($dirname)
    {
        clearstatcache();
        if(!file_exists($dirname))
        {
            mkdir($dirname);
        }
        clearstatcache();
        $success=file_exists($dirname);
        return($success);
    }
    
    static function Init()
    {
        $dirs_present=false;
        $dirs_present=$dirs_present || self::SetupDir(self::$dl_dir_common);
        $dirs_present=$dirs_present || self::SetupDir(self::$dl_dir_mp3);
        $dirs_present=$dirs_present || self::SetupDir(self::$yt_dl_temp_path);
        $dirs_present=$dirs_present || self::SetupDir(self::$jobpath);
        $dirs_present=$dirs_present || self::SetupDir(self::$cache_dir);    
        self::$youtubedl_video_standard=self::$yt_dl_format_1080p.self::$yt_dl_pls_no_sponsor;
        
        self::$args['tmp']=self::$yt_dl_temp_path;
        self::$args['out-tpl']=self::$output_format;
        
        self::FetchStaleJobs();
        self::LoadDownloader();
        return $dirs_present;
    }
    /**
     * Gets a list of useable URLs out of user submitted junk
     * @param string $inputstring
     * @return array of (potential) URLs
     */
    static function extractJob($inputstring)
    {
        $vlist=str_replace("\r","\n",$inputstring);
        $vlist=str_replace("\n\n","\n",$vlist);
        $videos=explode("\n",$vlist);
        $videosaccepted=[];
        // reject stuff like random bits and empty lines
        foreach($videos as $video)
        {
            // drop any spaces and other fluff
            $video=trim($video);
            // reject anything too short to be a link
            if(strlen($video)<10)
            {
                continue;
            }
            // might add support for pasting just youtube IDs later
            if(substr($video,0,4)!="http")
            {
                continue;
            }
            if(true)//idk what to write here
            {

            }
            $videosaccepted[]=$video;
        }
        return $videosaccepted;
    }
    /**
     * builds a command line for exec
     * @param string $dl_mode params to pick specific formats
     * @param type $jobid jobid as picked by hashing
     * @param type $vlist list of videos
     * @return string
     */
    static function buildCMD($dl_mode,$jobid,$vlist)
    {
        $vlist_args=implode(" ",array_map("escapeshellarg",$vlist));
        
        $cmd_prefix="";
        $cmd_postfix=" &";
        // TODO: add a windows check here and alter the above to "start /b " and "" respectively
        $windows=false;
        if($windows)
        {
            $cmd_prefix="start /b ";
            $cmd_postfix="";
        }
        
        $cmd=$cmd_prefix;
        
        $cmd.=self::$yt_dl_base;
        $cmd.=" --cache-dir ";
        $cmd.=escapeshellarg(self::$cache_dir);
        $cmd.=" -P ".self::$args['tmp'];
        $cmd.=" -o ".self::$args['out-path']."/".escapeshellarg(self::$output_format);
        $cmd.=$dl_mode;
        $cmd.=self::$removal_options;
        $cmd.=" -- ";
        $cmd.=$vlist_args;
        $cmd.="> ".self::$jobpath."/$jobid 2>&1";
        
        $cmd.=$cmd_postfix;
        
        return $cmd;
    }
    
    /**
     * Get job updates for a job at a specific index
     * @param int $index
     * @return array updates if job found
     */
    static function getJobUpdates($index)
    {
        if(!isset(self::$DOWNLOADER[$index]))
        {
            return [];
        }
        $jobid=self::$DOWNLOADER[$index]['jobid'];
        $filename=self::$jobpath."/".$jobid;
        // return an array of a single update saying yep that's gone ditch the whole thing
        if(!file_exists($filename))
        {
            return [["jobid"=>$jobid, "updateType"=>"job_gone"]];
        }
        // get the file and prepare it for processing
        $datafile=file_get_contents($filename);
        $datafile=str_replace("\r","\n",$datafile);
        $datafile=str_replace("\n\n","\n",$datafile);
        $data=explode("\n",$datafile);

        // keep track of which download of this job the previous line applied to
        $current_vid=self::$DOWNLOADER[$index]['current']??0;
        // as well as which lines were already processed
        $linesreceived=self::$DOWNLOADER[$index]['lines']??0;
        // go over every line
        $out=[];
        for($i=$linesreceived;$i<count($data);$i++)
        {
            // skip the empty line?   
            if($data[$i]!="")
            {
                $update=self::handleLine($data[$i]);
                $update['jobid']=$jobid;
                $update['current']=$current_vid;
                $current_vid=self::$DOWNLOADER[$index]['current'];
                $out[]= $update;
            }
        }
        // update the data 
        self::$DOWNLOADER[$index]['lines']=count($data)-1;

        return $out;
    }
    
    static function SetJobProp($prop,$value)
    {
        self::$DOWNLOADER[self::$updaterindex][$prop]=$value;
    }
    
    static function SendTheBigOne()
    {
        echo(json_encode(self::$DOWNLOADER));
        die;
    }
    
    /**
     * Consolidate updates from every currently active job (for this user)
     * @return array
     */
    static function getAllJobs()
    {
        $updates =[];
        for($i=0;$i<count(self::$DOWNLOADER);$i++)
        {
            self::$jobindex=$i;
            $updates=array_merge($updates, self::getJobUpdates($i));
        }
        return $updates;
    }
    /**
     * Processes a single line of yt-dlp output and extracts
     * useful information to report back to the user interface
     * @param type $line
     * @return array an update that can be sent to the UI
     */
    static function handleLine($line)
    {
        // find a tag first
        $tag="";
        if($line[0]=="[")
        {
            $rbracket=strpos($line,"]");
            if($rbracket!==false)
            {
                // extract the tag and send it on with the rest of the line
                $tag=substr($line,1,$rbracket-1);
                $rest=substr($line,$rbracket+2);
                return self::handleTag($tag,$rest);
            }
        }
        // if no tag was found look here
        return self::handleOther($line);
    }
    /**
     * Handles stuff without a [tag] at the start
     * @param type $line
     * @return array an update it can extract
     */
    static function handleOther($line)
    {
        // lines starting with error are bad news
        if(substr($line,0,5)=="ERROR")
        {
            self::$DOWNLOADER[self::$jobindex]['tasks'][self::$DOWNLOADER[self::$jobindex]['current']]['status']="error";
            self::$DOWNLOADER[self::$jobindex]['current']++;
            return [
              "updateType"=>"error"  
            ];
        }
        return [
            "updateType"=>"other",
            "message"=>$line
        ];
    }
    /**
     * Hands over the rest of the line to the appropriate [tag] handler
     * @param string $tag tag found
     * @param string $rest rest of the line
     * @return array the update that will be extracted
     */
    static function handleTag($tag, $rest)
    {
        switch(strtolower($tag))
        {
            case "download":
            {
                return self::handleDownload($rest);
            }
            case "info":
            {
                return self::handleInfo($rest);
            }
            // a hack. technically a [movefiles] can occur later.
            // problems for later
            case "movefiles":
            {
                return self::handleMoveFiles($rest);
            }
            // those are all postprocessing
            case "merger":
            case "modifychapters":
            case "sponsorblock":
            case "fixupm3u8":
            case "metadata":
            case "metadataparser":
            case "embedsubtitle":
            case "hlsnative":
            case "extractaudio":
            {
                self::$DOWNLOADER[self::$jobindex]['tasks'][self::$DOWNLOADER[self::$jobindex]['current']]['status']="processing";
                return [
                    "updateType"=>"processing"
                ];
            }
            // assume anything else is a site name like youtube or reddit or hamster or something
            default:
            {
                return self::handleProvider($tag);
            }
        }
    }
    /**
     * Converts shortened names back into full numbers. Not sure why but...
     * @param string $data a string like "640KiB"
     * @return float something like 655360
     */
    static function getDataSize($data)
    {
        if($data=="Unkno")
        {
            return 0.0;
        }
        $muls=[
            "i"=>0,
            "K"=>1,
            "M"=>2,
            "G"=>3
        ];
        $num="";
        $mul=0;
        for($i=0;$i<strlen($data);$i++)
        {
            if($data[$i]!=="." && !is_numeric($data[$i]))
            {
                if(!isset($muls[$data[$i]]))
                {
                    return 0;
                }
                $mul=$muls[$data[$i]];
                break;
            }
            $num.=$data[$i];
        }
        return floatval($num)*pow(1024,$mul);
    }

    
    static function handleMoveFiles($line)
    {
        $bits=explode('"',$line);
        $fname=basename($bits[count($bits)-2]);
        
        self::$DOWNLOADER[self::$jobindex]['tasks'][self::$DOWNLOADER[self::$jobindex]['current']]['filename']=$fname;
        self::$DOWNLOADER[self::$jobindex]['tasks'][self::$DOWNLOADER[self::$jobindex]['current']]['status']="done";
        self::$DOWNLOADER[self::$jobindex]['current']++;
        return [
            "updateType"=>"done",
            "index"=>intval(self::$DOWNLOADER[self::$jobindex]['current'])-1,
            "filename"=>$fname
        ];
    }
    
    /**
     * Handles [download] tags, for progress
     * @param type $line
     * @return array progress update
     */
    static function handleDownload($line)
    {
        
        // frustratingly enough, the output has plenty of different formats
        // 
        // YT       67.5% of 1.51MiB at 11.63MiB/s ETA 01:12
        // YT       67.5% of 1.51MiB at Unknown MiB/s ETA Unknown
        // Others   67.5% of ~ 1.51MiB at 11.63MiB/s ETA 01:12 (frag 666/777)
        // Subs?    1.01MiB at 11.63MiB/s (00:00:00)
        // Complete 100% of  1.51MiB in 00:04:27 at 11.63MiB/s  
        $line=trim(preg_replace('/[\s]+/', ' ', $line));
        $pieces=explode(" ",$line);
        $percent=0;
        $filesize=0;
        $speed=0;
        $timeleft=0;
        $complete=false;
        if($pieces[0]==="100%")
        {
            $complete=true;
        }
        for($i=0;$i<count($pieces);$i++)
        {
            $piece=$pieces[$i];

            // the "of" always follows percent and precedes full size
            if($piece=="of")
            {
                $percent=floatval(str_replace("%","",$pieces[$i-1]));
                if($pieces[$i+1]=="~")
                {
                    $filesize=self::getDataSize($pieces[$i+2]);
                }
                else
                {
                    $filesize=self::getDataSize($pieces[$i+1]);
                }
            }
            // the "at" is before speed
            if($piece=="at")
            {
                $speed=self::getDataSize(substr($pieces[$i+1],0,-2));
            }
            if($piece=="ETA")
            {
                $timeleft=$pieces[$i+1];
            }
        }
        //self::SetJobProp("progress",$percent)
        
        $statechange=  [
            "complete"=>$complete,
            "percent"=>$percent,
            "timeleft"=>$timeleft,
            "filesize"=>$filesize,
            "speed"=>$speed
        ];
        self::$DOWNLOADER[self::$jobindex]['tasks'][self::$DOWNLOADER[self::$jobindex]['current']]['progress']=$statechange;
        self::$DOWNLOADER[self::$jobindex]['tasks'][self::$DOWNLOADER[self::$jobindex]['current']]['status']="downloading";
        $statechange['updateType']='progress';
        return $statechange;
    }
    /**
     * Parses the [Info] tags. Unfinished
     * @param type $line
     * @return string
     */
    static function handleInfo($line)
    {
        $results=[];
        if(preg_match("|Downloading ([0-9]+) format|",$line,$results))
        {
            $data=[
              "updateType"=>"dlcount",
              "downloadcount"=>$results[1]
            ];
            return $data;
        }
    }
    /**
     * Handles any tag not specifically covered already, assuming it's the specific extractor/website
     * @param type $provider
     * @return array the update
     */
    static function handleProvider($provider)
    {
        $data=[
            "updateType"=>"provider",
            "provider"=>$provider
        ];
        self::$DOWNLOADER[self::$jobindex]['tasks'][self::$DOWNLOADER[self::$jobindex]['current']]['provider']=$provider;
        return $data;
    }
    
    /**
     * Try fetching job info for given ID
     * @param string Job ID
     * @return int index into the downloader array if found, -1 otherwise
     */
    static function getJobIndex($jobid)
    {
        for($i=0;$i<count(self::$DOWNLOADER);$i++)
        {
            if(self::$DOWNLOADER[$i]['jobid']===$jobid)
            {
                return $i;
            }
        }
        return -1;
    }
}


function ShowConfigurator()
{
    ?><!doctype html>
<html>
    <head>
        <title>YT-DLP Configuration Page</title>
    </head>
    <body>
        <p>This is the first time this application has been run, please complete setup.</p>
        <form action="index.php" method="POST">
            Video path:<input name="videopath" value="<?php echo YTDLP::$dl_dir_common;?>" /><br />
            Music path:<input name="musicpath" value="<?php echo YTDLP::$dl_dir_mp3;?>"  /><br />
            Job file path:<input name="jobpath" value="<?php echo YTDLP::$jobpath;?>"  /><br />
            Temp path:<input name="tempopath" value="<?php echo YTDLP::$yt_dl_temp_path;?>"  /><br />
            Cache Path:<input name="cachepath" value="<?php echo YTDLP::$cache_dir;?>"  /><br />
            Time to keep downloaded files, minutes:<input name="job_ttl" value="<?php echo YTDLP::$job_ttl;?>"  /><br />
            Username (leave blank to disable login):<input name="username" value="<?php echo YTDLP::$user;?>"  /><br />
            Password (leave blank to keep the same):<input name="password" type="password" /><br />
            Reset configuration password:<input name="erase_password" type="password" /><br />
            <button type="submit">Save</button>
        </form>
    </body>
</html>
<?php
}

function ShowLogin($error="")
{
    ?><!doctype html>
<html>
    <head>
        <title>Log in</title>
    </head>
    <body>
        <h3><?php echo $error; ?></h3>
        <form action="index.php?login" method="POST">
        Username:<input name="username" /><br />
        Password:<input name="password" type="password" /><br />
        <button type="submit">Log in</button>
        </form>
    </body>
</html>
<?php
}


function ConfirmErase($error="")
{
     ?><!doctype html>
<html>
    <head>
        <title>Erase configuration</title>
    </head>
    <body>
        <h3>Erase configuration</h3>
        <h2 style="color:red"><?php echo $error; ?></h2>
        <?php
            if(YTDLP::$erase_pass!="")
            {
        ?>
        <p>Enter the "Reset configuration password" you have configured to erase the configuration file and reset all settings.</p>
        <form action="index.php?reset" method="POST">
        <input name="erase_password" type="password" /><br />
        <button type="submit">Erase configuration file</button>
        </form>
        <?php
            }
            else
            {
        ?>
        <p>The "Erase configuration" feature is disabled because the "Reset configuration password" has not been set.</p>
        <?php
            }
        ?>
        <p><a href="index.php">Return</a></p>
    </body>
</html>
<?php
}

// get a hash if creating a pre-made file
if(isset($_GET['password']))
{
    echo password_hash($_GET['password'], PASSWORD_DEFAULT);
    die;
}







  ///////////////////////////////////////////\
 //  LET THE MOTHERFUCKING GAMES BEGIN   ////\\
////////////////////////////////////////////__\\




if(!YTDLP::LoadConfig())
{
    if(!isset($_POST['videopath']))
    {
        ShowConfigurator();
        die;
    }
    else
    {
        // i mean yeah but 
        // 1) if you run php as root you deserve everything that happens
        // 2) don't open your unconfigured app to the public
        // 3) it's a one-time thing (and you can include a premade file)
        YTDLP::$dl_dir_common=$_POST['videopath'];
        YTDLP::$dl_dir_mp3=$_POST['musicpath'];
        YTDLP::$jobpath=$_POST['jobpath'];
        YTDLP::$yt_dl_temp_path=$_POST['tempopath'];
        YTDLP::$cache_dir=$_POST['cachepath'];
        YTDLP::$job_ttl=$_POST['job_ttl'];
        if($_POST['username']!=="")
        {
            YTDLP::$user=$_POST['username'];
        }
        if($_POST['password']!=="")
        {
            YTDLP::$passhash=password_hash($_POST['password'], PASSWORD_DEFAULT);
        }
        if($_POST['erase_password']!=="")
        {
            YTDLP::$erase_pass=password_hash($_POST['erase_password'], PASSWORD_DEFAULT);
        }
        YTDLP::SaveConfig();
        header("Location: index.php");
        die;
    }
}

if(isset($_GET['reset']))
{
    if(YTDLP::$erase_pass=="")
    {
        ConfirmErase();
        die;
    }
    
    if(isset($_POST['erase_password']))
    {
        if(password_verify($_POST['erase_password'],YTDLP::$erase_pass))
        {
            unlink(YTDLP::$config_file);
            header("Location: index.php");
            die;
        }
        else
        {
            ConfirmErase("Error: incorrect password.");
            die;
        }
    }
    else
    {
        ConfirmErase();
            die;
    }
}


if(!YTDLP::Init())
{
    ?>
<h2>We had issue with setting up the directories.</h2>
<?php
die;
}



if(isset($_GET['logout']))
{
    unset($_SESSION['user']);
}


// login attemptss >:3
if(isset($_GET['login']))
{
    $user=$_POST['username'];
    $pass=password_verify($_POST['password'],YTDLP::$passhash);
    if($pass && $user===YTDLP::$user)
    {
        $_SESSION['user']=$user;
    }
    else
    {   
        ShowLogin("haha nope");
        die;
    }
}
// if username was set blank, don't ask auth
if(YTDLP::$user!=="" && !isset($_SESSION['user']))
{
    ShowLogin();
    die;
}

// the regular stuff as scheduled
if(isset($_POST['urls']))
{
    
    $videos= YTDLP::extractJob($_POST['urls']);
    $jobid=md5($_POST['urls'].time());
    
    $mode=$_POST['mode']??"video";
    
    
    
    YTDLP::$args['tmp']="temp:".YTDLP::$args['tmp'];
    $yt_dl_mode=YTDLP::$youtubedl_video_standard;
    $dl_dir=YTDLP::$dl_dir_common;
    
    switch($mode)
    {
        case "mp3":
        {
            $yt_dl_mode=YTDLP::$youtubemp3;
            $dl_dir=YTDLP::$dl_dir_mp3;
            break;
        }
        default:
        {
            $mode="video";
        }
    }
    YTDLP::$args['out-path']="$dl_dir/$jobid";
    
    @mkdir($args['out-path']);
    
    $argsflat="";
    
    foreach(YTDLP::$args as $name=>&$value)
    {
        $value= escapeshellarg($value);
    }//------------------------------------------------------  -o {$args['out-tpl']}
    $cmd=YTDLP::buildCMD($yt_dl_mode,$jobid,$videos);
    
    $response=[
        "status"=>"started",
        "mode"=>$mode,
        "vidcount"=>count($videos),
        "list"=>$videos,
        "jobid"=>$jobid,
        "cmd"=>$cmd
    ];
    $dlinfo=[
        "jobid"=>$jobid,
        "current"=>0,
        "lines"=>0,
        "tasks"=>[]
    ];
    foreach($videos as $line)
    {
        $dlinfo['tasks'][]=[
            "mode"=>$mode,
            "status"=>"waiting",
            "provider"=>"unknown",
            "progress"=>0
        ];
    }
    YTDLP::$DOWNLOADER[]=$dlinfo;
    YTDLP::SaveDownloader();
    echo str_pad(json_encode($response),2048," ");
    flush();
    exec($cmd);
    die();
    
}


// get progress on the specific job
if(isset($_GET['info']))
{
    $jobs=YTDLP::getAllJobs();
    YTDLP::SaveDownloader();
    echo json_encode($jobs);
    die;
}

if(isset($_GET['refresh']))
{
    YTDLP::SendTheBigOne();
}

if(isset($_GET['dljob']) && isset($_GET['dlidx']))
{
    $dljobid=$_GET['dljob'];
    $dlidx=intval($_GET['dlidx']);
    $force_dl=isset($_GET['force']);
    if(YTDLP::getJobIndex($dljobid)!==-1)
    {
        $path="";
        $job=YTDLP::$DOWNLOADER[YTDLP::getJobIndex($dljobid)]??die("job not found");
        $task=$job['tasks'][$dlidx]??die("task not found");
        $mode=$task['mode']??die("mode not found");
        $fname=$task['filename']??die("filename not found");
        $path.=$mode==="mp3"?YTDLP::$dl_dir_mp3:YTDLP::$dl_dir_common;
        $path.="/";
        $path.=$dljobid;
        $path.="/";
        $path.=$fname;
        if(file_exists($path))
        {
            $ctype=($mode==="mp3")?"audio/mp3":"video/mp4";
            if($force_dl)
            {
                header("Content-Disposition: attachment; filename=\"".rawurlencode($fname)."\"");
            }
            
            header("Content-Type: ".$ctype);
            header("Content-Length: ".filesize($path));
            $file=fopen($path,"r");
            fpassthru($file);
            fclose($file);
            die;
        }
        die("file not found");
    }
    die("job not found");
}

// only html  beyond this point

?><!doctype html>
<html>
    <head>
        <title>yt-dlp</title>
        <script type="text/javascript">
        
        
        function CheckMoreData()
        {
            console.log(window.ytdlp_done+">="+window.ytdlp_total);
            if(window.ytdlp_done>=window.ytdlp_total)
            {
                window.clearInterval(window.refresher);
            }
        }
        
        
        function RequestUpdate()
        {
            let ajax = new XMLHttpRequest();
            ajax.onreadystatechange=function()
            {
                if(ajax.readyState===4)
                {
                    if(ajax.status === 200)
                    {
                       handleUpdateList(JSON.parse(ajax.responseText));
                       CheckMoreData();
                    }
                }
            };
            ajax.open("GET","index.php?info=");
            ajax.send();
        }
        function handleUpdateList(list)
        {
            if(!list)
            {
                console.log("nope");
                return;
            }
                
            for(var i=0;i<list.length;i++)
            {
                
                handleUpdate(list[i]);
            }
        }
        function handleUpdate(update)
        {
            if(!update)
                return;
            
            var taskid=update.jobid+update.current;
            var t=document.getElementById("vid"+taskid);
            if(!t)
            {
                
                return;
            }
            var s=document.getElementById("stat"+taskid);
            var p=document.getElementById("pro"+taskid);
            var pn=document.getElementById("pron"+taskid);
            switch(update.updateType)
            {
                case "done":
                {
                    window.ytdlp_done++;
                    console.log(update);
                    var td=s.parentElement;
                    td.removeChild(s);
                    
                    s=document.createElement("a");
                    s.id='stat'+taskid;
                    s.dataset.status="done";
                    s.href="index.php?dljob="+update.jobid+"&dlidx="+update.index;
                    td.appendChild(s);
                    var forcelink=document.createElement("a");
                    forcelink.href="index.php?force=true&dljob="+update.jobid+"&dlidx="+update.index;
                    forcelink.append(" ⬇ ");
                    td.appendChild(forcelink);
                    break;
                }
                case "error":
                {
                    window.ytdlp_done++;
                    console.log(update);
                    s.dataset.status="error";
                    p.dataset.progress="failed";
                    pn.dataset.progress="failed";
                    break;
                }
                case "progress":
                {
                    console.log(update);
                    s.dataset.status="downloading";
                    p.dataset.progress=update.percent;
                    p.style.width=update.percent+"%";
                    pn.dataset.progress=update.percent+"%";
                    break;
                }
                case "provider":
                {
                    t.dataset.provider=update.provider.toLowerCase();
                    break;
                }
                case "other":
                {
                    console.log(update.message);
                    break;
                }
            }
        }
        
        function StartRequestor()
        {
            console.log("starting requestor");
            window.refresher=window.setInterval(RequestUpdate, 500);
            CheckMoreData();
        }
        
        function StartFetchUpdate()
        {
            let ajax = new XMLHttpRequest();
            ajax.onreadystatechange=function()
            {
                if(ajax.readyState===4)
                {
                    if(ajax.status === 200)
                    {
                       doFullReload(JSON.parse(ajax.responseText));
                    }
                }
            };
            ajax.open("GET","index.php?refresh=",true);
            ajax.send();
        }
        
        function getVideo()
        {
             let ajax = new XMLHttpRequest();
            ajax.onreadystatechange=function()
            {
                if(ajax.readyState===4)
                {
                    if(ajax.status === 200)
                    {
                       showInfo(ajax.responseText);
                    }
                }
            };
            var format=document.getElementById('controls').elements['format'].value;
            var urls=document.getElementById("url").value;
            document.getElementById("url").value="";
            ajax.open("POST","index.php",true);
            ajax.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
            ajax.send("urls="+encodeURIComponent(urls)+"&mode="+encodeURIComponent(format));
        
            //window.setTimeout(StartRequestor, 1000);
        }
        
        function doFullReload(jobs)
        {
            console.log(jobs);
            var t=document.getElementById("status").getElementsByTagName('tbody')[0];
            t.innerHTML="";
            window.clearInterval(window.refresher);
            window.ytdlp_total=0;
            window.ytdlp_done=0;
            for(var i=0;i<jobs.length;i++)
            {
                console.log("job "+i);
                DisplayJob(jobs[i]);
                console.log("job "+i+" done");
            }
            console.log("display done");
            StartRequestor();
        }
        
        function DisplayJob(job)
        {
           
           console.log(job);
           for(var i=0;i<job.tasks.length;i++)
            {
                var taskid=job.jobid+i;
                var task=job.tasks[i];
                var t=document.getElementById("status").getElementsByTagName('tbody')[0];;
                var tr=document.createElement("tr");
                var td_type=document.createElement("td");
                var td_progress=document.createElement("td");
                var td_status=document.createElement("td");
                tr.appendChild(td_type);
                tr.appendChild(td_progress);
                tr.appendChild(td_status);
                var provider_indicator=document.createElement("span");
                provider_indicator.id='vid'+taskid;
                provider_indicator.dataset.provider=task.provider.toLowerCase();
                provider_indicator.append("\xa0");
                td_type.appendChild(provider_indicator);
                var percent_container=document.createElement("div");
                var percent_bar=document.createElement("span");
                var percent_number=document.createElement("span");
                percent_number.append("\xa0");
                percent_bar.id='pro'+taskid;
                percent_bar.className="progress";
                percent_bar.style.width=task.progress.percent+"%";
                percent_bar.dataset.progress=task.progress.percent;
                percent_number.className="progressNumber";
                percent_number.id='pron'+taskid;
                percent_container.appendChild(percent_bar);
                percent_container.appendChild(percent_number);
                td_progress.appendChild(percent_container);
                var status_icon=task.status==="done"?document.createElement("a"):document.createElement("span");
                status_icon.id='stat'+taskid;
                status_icon.dataset.status=task.status;
                status_icon.append("\xa0");
                td_status.appendChild(status_icon);
                window.ytdlp_total++;
                switch(task.status)
                {
                    case "downloading":
                    {
                        percent_bar.dataset.progress=task.progress.percent;
                        percent_number.dataset.progress=task.progress.percent+"%";
                        break;
                    }
                    case "error":
                    {
                        window.ytdlp_done++;
                        percent_bar.dataset.progress="failed";
                        percent_number.dataset.progress="failed";
                        break;
                    }
                    case "done":
                    {
                        window.ytdlp_done++;
                        percent_bar.dataset.progress=task.progress.percent;
                        percent_number.dataset.progress=task.progress.percent+"%";
                        status_icon.href="index.php?dljob="+job.jobid+"&dlidx="+i;
                        var forcelink=document.createElement("a");
                        forcelink.href="index.php?force=true&dljob="+job.jobid+"&dlidx="+i;
                        forcelink.append(" ⬇ ");
                        td_status.appendChild(forcelink);
                        break;
                    }
                    default:
                    {
                        percent_bar.dataset.progress="";
                    }
                }
                t.appendChild(tr);
                
            } 
        }
        
        function showInfo(info)
        {
            console.log(info);
            window.dlInfo=JSON.parse(info);
            window.dlInfo.current=0;
            document.getElementById("vidcount").innerHTML=window.dlInfo.vidcount;
            document.getElementById("jobid").innerHTML=window.dlInfo.jobid;
            document.getElementById("mode").innerHTML=window.dlInfo.mode;
            StartFetchUpdate();
        }
        
        window.ytdlp_total=0;
        window.ytdlp_done=0;
        window.onload=function(){StartFetchUpdate();};
        </script>
        <style>
            *
            {
                box-sizing:border-box;
            }
            span.progress
            {
                position:absolute;
                top:0;
                left:0;
                height:100%;
                width:0;
                display:inline-block;
                background-color: #3F7FFF;
                transition: width 0.5s;
                content:" ";
            }
            span.progressNumber::before
            {
                position:absolute;
                top:0;
                left:0;
                height:100%;
                width: 100%;
                display:inline-block;
                text-align: center;
                font-size:2em;
                font-weight:bold;
                content: attr(data-progress);
            }
            [data-provider]::before
            {
                content:"?";
            }
            [data-provider=youtube]::before
            {
                border-radius:3px;
                content:'▶';
                color:white;
                background-color:red;
                padding:3px;
                padding-left:10px;
                padding-right:10px;
                
            }
            [data-provider=xhamster]::before
            {
                content: '🐹';
            }
            [data-provider=pornhub]::before
            {
                background-color: black;
                color: orange;
                content: 'PH';
            }
            [data-provider=reddit]::before
            {
                content: '👽';
            }
            
            [data-status=waiting]::before
            {
                content: '⏳';
            }
            [data-status=downloading]::before
            {
                content: '♻';
            }
            [data-status=processing]::before
            {
                content: '⚙';
            }
            [data-status=done]::before
            {
                content: '✔';
            }
            [data-status=error]::before
            {
                content: '❌';
            }
            
            [data-progress=failed]::before
            {
                min-width:50%;
                background-color: red;
            }
            #status
            {
                width:100%;
            }
            #status td
            {
                position:relative;
                height:3em;
            }
            #infoheader
            {
                font-size:2em;
            }
            #vicount::before
            {
                content:"Videos: ";
            }
            #jobid::before
            {
                content:"Job ID: ";
            }
            #mode
            {
                font-variant: small-caps;
                font-family: monospace;
            }
            #mode::before
            {
                content:"Mode: ";
            }
        </style>
    </head>
    <body><form id="controls">
            <input type="radio" name="format" id="sel-video" value="video" checked /><label for="sel-video">Video 1080p</label>
            <input type="radio" name="format" id="sel-mp3" value="mp3" /><label for="sel-mp3">MP3</label>
            <textarea id="url" style="width:100%;height:36vh" ></textarea><button onclick="getVideo();" type="button">go</button></form><br />
            <div id="infohearder">
                <span id="vidcount"></span> <span id="jobid"></span> <span id="mode"></span>
            </div>
            <table id="status">
                <thead>
                    <tr>
                        <th>&nbsp;</th><th>Progress</th><th>Status</th>
                    </tr>
                </thead>
                <tbody>
                </tbody>
        </table>
            <br />
            <a href="index.php?reset">reset configuration</a><br />
            <a href="index.php?logout">log out</a><br />
    </body>
</html>