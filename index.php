<?php
class YTDLP
{
    public static $yt_dl_base='yt-dlp --cache-dir .cache';
    public static $output_format ="%(title)s.%(ext)s";
    public static $yt_dl_temp_path="./tmp";
    public static $dl_dir_common='files';
    public static $dl_dir_mp3='mp3';
    public static $yt_dl_format_1080p=' -S res:1080,fps,+codec:avc:m4a ';
    public static $yt_dl_subs_yes=' --write-sub --write-auto-sub --sub-langs "en,no,no-nb,no-bm,ru,nl,nl-be" --embed-subs --compat-options no-keep-subs ';
    public static $yt_dl_pls_no_sponsor=' --sponsorblock-remove sponsor --sponsorblock-mark all,-filler ';
    public static $youtubedl_video_standard;
    public static $youtubemp3=" -x --audio-format mp3 ";
    public static $jobpath="jobs";
    public static $args=[];

    public static $DOWNLOADER=[];
    
    static $jobindex=0;
    
    static function Init()
    {
        self::$youtubedl_video_standard=self::$yt_dl_format_1080p.self::$yt_dl_pls_no_sponsor;
        
        self::$args['tmp']=self::$yt_dl_temp_path;
        self::$args['out-tpl']=self::$output_format;
        if(isset($_COOKIE['downloader']))
        {
            self::$DOWNLOADER=[];
            setcookie("downloader",json_encode(self::$DOWNLOADER),time()+60*60*24*30);
        }
        else
        {
            self::$DOWNLOADER=json_decode(stripslashes($_COOKIE['downloader']));
        }
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
        
        $cmd="";
        
        $cmd.=self::$yt_dl_base;
        $cmd.=" -P ".self::$args['tmp'];
        $cmd.=" -P ".self::$args['out-path'];
        $cmd.=$dl_mode;
        $cmd.=" -- ";
        $cmd.=$vlist_args;
        $cmd.="> ".self::$jobpath."/$jobid 2>&1";
        
        $cmd.=" &";
        
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
        $filename=$jobpath."/".$jobid;
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
                $update=handleLine($data[$i]);
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
            array_merge($updates, self::getJobUpdates($i));
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
                self::$DOWNLOADER[self::$jobindex]['tasks'][self::$DOWNLOADER[self::$jobindex]['current']]['status']="done";
                self::$DOWNLOADER[self::$jobindex]['current']++;
                return [
                    "updateType"=>"done"
                ];
            }
            // those are all postprocessing
            case "merger":
            case "modifychapters":
            case "sponsorblock":
            case "fixupm3u8":
            case "metadata":
            case "embedsubtitle":
            case "hlsnative":
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
                $mul=$muls[$data[$i]];
                break;
            }
            $num.=$data[$i];
        }
        return floatval($num)*pow(1024,$mul);
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
                    $filesize=getDataSize($pieces[$i+2]);
                }
                else
                {
                    $filesize=getDataSize($pieces[$i+1]);
                }
            }
            // the "at" is before speed
            if($piece=="at")
            {
                $speed=getDataSize(substr($pieces[$i+1],0,-2));
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
        if(preg_match("Downloading ([0-9]+) format",$line,$results))
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
    
}


/**
 * Try fetching job info for given ID
 * @param string Job ID
 * @return int index into the downloader array if found, -1 otherwise
 */
function getJobIndex($jobid)
{
    for($i=0;$i<count($DOWNLOADER);$i++)
    {
        if($DOWNLOADER[$i]['jobid']===$jobid)
        {
            return $i;
        }
    }
    return -1;
}




  ///////////////////////////////////////////
 //  LET THE MOTHERFUCKING GAMES BEGIN   ///
///////////////////////////////////////////




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
        "current"=>0,
        "lines"=>0,
        "tasks"=>[]
    ];
    foreach($videos as $line)
    {
        $dlinfo['tasks'][]=[
            "status"=>"waitng",
            "provider"=>"unknown",
            "progress"=>0
        ];
    }
    YTDLP::$DOWNLOADER[]=$dlinfo;
    echo str_pad(json_encode($response),2048," ");
    flush();
    exec($cmd);
    setcookie("downloader",json_encode(YTDLP::$DOWNLOADER),time()+60*60*24*30);
    die();
    
}

setcookie("downloader",json_encode(YTDLP::$DOWNLOADER),time()+60*60*24*30);
// get progress on the specific job
if(isset($_GET['info']))
{
    echo json_encode(YTDLP::getAllJobs());
    die;
}

if(isset($_GET['refresh']))
{
    YTDLP::SendTheBigOne();
}

// only html  beyond this point

?><!doctype html>
<html>
    <head>
        <title>yt-dlp</title>
        <script type="text/javascript">
        
        
        
        function ShowPercent(value)
        {
            p=document.getElementById('percent');
            p.style.display="inline-block";
            p.innerHTML=value + "%";
            if(value==100)
            {
                p.innerHTML="Done";
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
                    }
                }
            };
            ajax.open("GET","index.php?info=a&jobid="+window.dlInfo.jobid);
            ajax.send();
        }
        function handleUpdateList(list)
        {
            if(!list)
            {
                console.log("nope");
                return;
            }
                
            for(i=0;i<list.length;i++)
            {
                
                handleUpdate(list[i]);
            }
        }
        function handleUpdate(update)
        {
            if(!update)
                return;
            var t=document.getElementById("vid"+window.dlInfo.jobid+window.dlInfo.current);
            var s=document.getElementById("stat"+window.dlInfo.jobid+window.dlInfo.current);
            var p=document.getElementById("pro"+window.dlInfo.jobid+window.dlInfo.current);
            var pn=document.getElementById("pron"+window.dlInfo.jobid+window.dlInfo.current);
            switch(update.updateType)
            {
                case "done":
                {
                    console.log(update);
                    s.innerHTML="✔";
                    nextVideo();
                    break;
                }
                case "error":
                {
                    
                    console.log(update);
                    s.innerHTML="❌";
                    p.style.backgroundColor="red";
                    p.style.minWidth="50%";
                    nextVideo();
                    break;
                }
                case "progress":
                {
                    pn.innerHTML=update.percent+"%";
                    p.style.width=update.percent+"%";
                    s.innerHTML="♻";
                    break;
                }
                case "provider":
                {
                    t.className="provider_"+update.provider.toLowerCase();
                    break;
                }
                case "other":
                {
                    console.log(update.message);
                    break;
                }
            }
        }
        
        function nextVideo()
        {
            window.dlInfo.current++;
            if(window.dlInfo.current>=window.dlInfo.vidcount)
            {
                window.clearInterval(window.refresher);
                window.dlInfo.current=0;
            }
        }
        function StartRequestor()
        {
            window.refresher=window.setInterval(RequestUpdate, 500);
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
            ajax.open("GET","index.php?url="+encodeURIComponent(urls)+"&mode="+encodeURIComponent(format));
            ajax.send();
        
            //window.setTimeout(StartRequestor, 1000);
        }
        
        function showInfo(info)
        {
            //document.getElementById("percent").innerHTML=info;
            window.dlInfo=JSON.parse(info);
            window.dlInfo.current=0;
            document.getElementById("vidcount").innerHTML=window.dlInfo.vidcount;
            document.getElementById("jobid").innerHTML=window.dlInfo.jobid;
            document.getElementById("mode").innerHTML=window.dlInfo.mode;
            for(i=0;i<window.dlInfo.vidcount;i++)
            {
                var t=document.getElementById("status").getElementsByTagName('tbody')[0];;
                var tr=document.createElement("tr");
                var td_type=document.createElement("td");
                var td_progress=document.createElement("td");
                var td_status=document.createElement("td");
                tr.appendChild(td_type);
                tr.appendChild(td_progress);
                tr.appendChild(td_status);
                td_type.innerHTML+=' <span id="vid'+window.dlInfo.jobid+i+'">&nbsp;</span><br />';
                td_progress.innerHTML='<div><span class="progress" id ="pro'+window.dlInfo.jobid+i+'">&nbsp;</span><span class="progressNumber" id ="pron'+window.dlInfo.jobid+i+'">&nbsp;</span></div>';
                td_status.innerHTML+=' <span id="stat'+window.dlInfo.jobid+i+'">⏳.</span><br />';
                t.appendChild(tr);
            }
            StartRequestor(window.dlInfo.jobid);
        }
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
            }
            span.progressNumber
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
            }
            .provider_youtube::before
            {
                border-radius:3px;
                content:'▶';
                color:white;
                background-color:red;
            }
            .provider_xhamster::before
            {
                content: '🐹';
            }
            .provider_pornhub::before
            {
                background-color: black;
                color: orange;
                content: 'PH';
            }
            .provider_reddit::before
            {
                content: '👽';
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
        </style>
    </head>
    <body><form id="controls">
            <input type="radio" name="format" id="sel-video" value="video" checked /><label for="sel-video">Video 1080p</label>
            <input type="radio" name="format" id="sel-mp3" value="mp3" /><label for="sel-mp3">MP3</label>
            <textarea id="url" style="width:100%;height:50vh" ></textarea><button onclick="getVideo();" type="button">go</button></form><br />
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
            <a href="/files">download dir</a><br />
            <a href="/mp3">music</a><br />
    </body>
</html>