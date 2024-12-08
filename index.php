<?php
if(isset($_COOKIE['downloader']))
{
    setcookie("downloader","[]",time()+60*60*24*30);
    $DOWNLOADER=[];
}
else
{
    $DOWNLOADER=json_decode(stripslashes($_COOKIE['downloader']));
}
function handleLine($line)
{
    // find a tag first
    $tag="";
    if($line[0]=="[")
    {
        $rbracket=strpos($line,"]");
        if($rbracket!==false)
        {
            $tag=substr($line,1,$rbracket-1);
            $rest=substr($line,$rbracket+2);
            return handleTag($tag,$rest);
        }
    }
    return handleOther($line);
}

function handleOther($line)
{
    if(substr($line,0,5)=="ERROR")
    {
        return [
          "updateType"=>"error"  
        ];
    }
    return [
        "updateType"=>"other",
        "message"=>$line
    ];
}

function handleTag($tag, $rest)
{
    switch(strtolower($tag))
    {
        case "download":
        {
            return handleDownload($rest);
        }
        case "info":
        {
            return handleInfo($rest);
        }
        case "metadata":
        {
            return [
                "updateType"=>"done"
            ];
        }
        case "merger":
        case "modifychapters":
        case "sponsorblock":
        case "fixupm3u8":
        case "movefiles":
        case "embedsubtitle":
        case "hlsnative":
        {
            return [
                "updateType"=>"processing"
            ];
        }
        default:
        {
            return handleProvider($tag);
        }
    }
}

function getDataSize($data)
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

function handleDownload($line)
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
    return [
        "updateType"=>"progress",
        "complete"=>$complete,
        "percent"=>$percent,
        "timeleft"=>$timeleft,
        "filesize"=>$filesize,
        "speed"=>$speed
    ];
}

function handleInfo($line)
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
function handleProvider($provider)
{
    $data=[
        "updateType"=>"provider",
        "provider"=>$provider
    ];
    return $data;
}

function getJobUpdates($jobid)
{
     if(!isset($_SESSION['jobs']))
    {
        $_SESSIOn['jobs']=[];
    }
    if(!isset($_SESSION['jobs'][$jobid]))
    {
        $_SESSION['jobs'][$jobid]=[];
    }
    $filename=$jobpath."/".$jobid;
    if(!file_exists($filename))
    {
        echo ("$filename :(");
        die;
    }
    $datafile=file_get_contents($filename);
    $datafile=str_replace("\r","\n",$datafile);
    $datafile=str_replace("\n\n","\n",$datafile);
    $data=explode("\n",$datafile);
    //$data=array_reverse($data);
    if($_GET['info']==="dump")
    {
        var_dump($data);
        die;
    }
    $current_vid=$_SESSION['jobs'][$jobid]['current']??0;
    $linesreceived=$_SESSION['jobs'][$jobid]['lines']??0;
    $out=[];
    for($i=$linesreceived;$i<count($data);$i++)
    {
           
        if($data[$i]!="")
        {
            $update=handleLine($data[$i]);
            $update['jobid']=$jobid;
            $update['current']=$current_vid;
            if($update['updateType']=="done")
            {
                $current_vid++;
            }
            $out[]= $update;
        }
    }
    $_SESSION['jobs'][$jobid]['current']=$current_vid;
    $_SESSION['jobs'][$jobid]['lines']=count($data)-1;
    return json_encode($out);
}

function getAllJobs()
{
    
}


session_start();
$yt_dl_base='yt-dlp --cache-dir .cache';
$output_format ="%(title)s.%(ext)s";
$yt_dl_temp_path="./tmp";
$dl_dir_common='files';
$dl_dir_mp3='mp3';
$yt_dl_format_1080p=' -S res:1080,fps,+codec:avc:m4a ';
$yt_dl_subs_yes=' --write-sub --write-auto-sub --sub-langs "en,no,no-nb,no-bm,ru,nl,nl-be" --embed-subs --compat-options no-keep-subs ';
$yt_dl_pls_no_sponsor=' --sponsorblock-remove sponsor --sponsorblock-mark all,-filler ';
$youtubedl_video_standard=$yt_dl_format_1080p.$yt_dl_pls_no_sponsor;
$youtubemp3=" -x --audio-format mp3 ";
$jobpath="jobs";
$args=[];
$args['tmp']=$yt_dl_temp_path;
$args['out-tpl']=$output_format;
/* 
 * Click nbfs://nbhost/SystemFileSystem/Templates/Licenses/license-default.txt to change this license
 * Click nbfs://nbhost/SystemFileSystem/Templates/Scripting/EmptyPHP.php to edit this template
 */

if(isset($_GET['url']))
{
    $vlist=$_GET['url'];
    $vlist=str_replace("\r","\n",$vlist);
    $vlist=str_replace("\n\n","\n",$vlist);
    $videos=explode("\n",$vlist);
    $vlist="";
    $videocount=0;
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
        $videocount++;
        $vlist.=(escapeshellarg($video)." ");
        $videosaccepted[]=$video;
    }
    $jobid=md5($vlist.time());
    $_SESSION['jobid']=$jobid;
    $mode=$_GET['mode']??"video";
    
    
    
    $args['tmp']="tmp:".$args['tmp'];
    $yt_dl_mode=$youtubedl_video_standard;
    $dl_dir=$dl_dir_common;
    switch($mode)
    {
        case "mp3":
        {
            $yt_dl_mode=$youtubemp3;
            $dl_dir=$dl_dir_mp3;
            break;
        }
        default:
        {
            $mode="video";
        }
    }
    $args['out-path']="$dl_dir/$jobid";
    
    @mkdir($args['out-path']);
    
    $argsflat="";
    
    foreach($args as $name=>&$value)
    {
        $value= escapeshellarg($value);
    }//------------------------------------------------------  -o {$args['out-tpl']}
    $cmd="$yt_dl_base -P {$args['tmp']} -P {$args['out-path']}  $yt_dl_mode -- $vlist > $jobpath/$jobid 2>&1 &";
    $response=[
        "status"=>"started",
        "mode"=>$mode,
        "vidcount"=>$videocount,
        "list"=>$videosaccepted,
        "jobid"=>$jobid,
        "cmd"=>$cmd
    ];
//    $cmd=$yt_dl_base.$dl_dir.$yt_dl_mode . $vlist . " > $jobpath/$jobid/progress.txt 2>&1 &";
    session_write_close();
    
    echo str_pad(json_encode($response),2048," ");
    flush();
    exec($cmd);
    //session_start();
    //echo "launched";
    die();
    
}
// get progress on the specific job
if(isset($_GET['info']))
{
    $jobid=$_GET['jobid'] ?? die();
    echo getJobUpdates($jobid);
    //echo "loading...";
    die;
}

if(isset($_GET['listjobs']))
{
    echo getAllJobs();
    die;
}
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