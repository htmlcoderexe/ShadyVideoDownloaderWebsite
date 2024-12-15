Okay, what is this? Brace yourselves, because it is the

### Single-file 

## Video

# Downloader!

Wait, what?

That's right, the one single file (``index.php``) contains (nearly) everything that's needed to have your very own, self-hosted shady video downloader website instance. More about what is NOT contained in here... below.

## Requirements

Okay, this single file is really awesome (I know, I wrote it myself!), but (sadly) it is not godlike (yet).

Therefore, it will need some things...

### ``yt-dlp`` 

This is the part doing the hard work of clawing those videos out of the internet.

If you are on Windows, you can just drag the EXE file of yt-dlp (as well as all the other crap that one wants like ffmpeg and whatever else) into the same folder you'll be dropping this baby in, for Linux, however, there are... steps.

1) get Python, should be at least like 3.9, bigger number = better (probably).

2) if the Python you got from your package manager is not good enough, get specific (``apt-get install python3.9`` and all that).

3) get pip (if your Python came without)

4) ``python -m pip install yt-dlp``

5) yeah! (I hope)

> [!WARNING]
> ``yt-dlp`` from your package manager will be crap, same applies from the one from that one command you see online, something about ``curl``ing it from somewhere and running that. Been there, done that - crap. The above works!

> [!WARNING]
> There are some issues on Windows (because of Windows being Windows), we're working on that. Meantime go curse Bill Gates with extra greasy beard hair or whatever.

### some sort of a server that can run PHP

Oh yeah, since it is a PHP file, it will need something that can PHP all that PHP I wrote. No server = no PHP-ing.

Most people just get Apache and all the other stuff that goes along with it. Probably the easiest - note this won't require anything but PHP plus something that talks HTTP.

In theory, even PHP's built-in dev server thing works, but it's like double crap so don't use it. Will be worse than Windows.

So anyway, go get your server set up, put the file in the web root (or into a subfolder, this file isn't picky), navigate to the address with a browser and get ready to enjoy the many

## Features

Yes, as a software product, this thing naturally comes with features, such as...

### Out-of-box experience

That's right, it will run you through a first-time setup. It's really really self-sufficient, will create whatever files and folders are needed, and even comes with sane (mostly) defaults.

### Configuration options

See above, you'll get to pick the folders it uses to store whatever weird smut it is you needed this for. There is also a possibility to setup

### Optional authentication

In case you want this on the open web, probably smart to set up a username and a password. No, there won't be a default, because then every idiot will get hacked with admin/admin or whatever it is that's cool and trending these days.

### Housekeeping

All files past 120 minutes will be automatically erased - this is not meant as a permanent storage, rather an actually working, self-hosted copy of those sketchy "youtube downloader" websites. Which means that it also has

### A fancy interface

Okay, "fancy" might be stretching it, but you get progress bars, download links once something finishes, and you can reload or leave the page without losing anything (as long as you come back before the files get erased). There are even little icons for some of the more popular services you would want this for (more to come!). Did I mention that all of this is **ONE SINGLE FILE**?

### MP3 mode

Oh yeah, another feature stolen from the shady websites - you can get just the sound.

## Anything else?

I dunno, you can probably plaster ads all over it or something to recreate the authentic experience? 🤷‍♀️