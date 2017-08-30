<?php
/*
<! File                 : woonveilig-service.php                            >
<! Project              : Woonveilig                                        >
<! Created by           : Bjorn van den Brule                               >
<! Website              : www.brule.nl                                      >
<! Copyright            : Van den Brule Consultancy 2015                    >
<! Date                 : 17-05-2015                                        >
<! Supporting documents : PHP 5.x                                           >
<! Licentie             : GPL                                               >
<! Description          : This file contains the main entry                 >
<!                                                                          >
<!                                                                          >
<! Edit history                                                             >
<!                                                                          >
<! No           Date            Comments                                by  >
<! -----        --------        ----------------------------------      --- >
<! V1.00        17/05/15        Created, HELLO world !!!!!              abb >
*/

define('LOCK_FILE', "/dev/shm/" . basename($argv[0], ".php") . ".lock");

if (!tryLock())
    die("Already running.\n");

# remove the lock on exit (Control+C doesn't count as 'exit'?)
register_shutdown_function('unlink', LOCK_FILE);

# The rest of your script goes here....

$dd = date('d-m-Y H:m:s');
echo "Woonveilig service, see syslog for messages.\n";

/* Constants */
define("C_ARMED", "Armed");
define("C_DISARMED", "Disarmed");
define("C_HOME", "Home");
define("C_ON", 1);
define("C_OFF", 0);

/* mail setting */
$to = "xxxxxxxxx@gmail.com";         /* send alarm email to */
$from = "xxxxxxx@caiway.nl";        /* email from *?

/* report server settings */
$port = 8500;
$addr = "localhost";

/* vars */
$last_event = 0;
$event = 1;
$system = C_DISARMED;
$alarm = C_OFF;
$cnt_twenty = 0;
$new_event = false;

/* Syslog init */
/* Tip: tail -f /var/log/syslog | grep Woonveilig */
openlog("Woonveilig", LOG_PID | LOG_PERROR, LOG_LOCAL0);

/* listen on port */
$sock = socket_create_listen($port, 128);
socket_getsockname($sock, $addr, $port);
syslog(LOG_INFO, "Server listening on $addr:$port");

function tryLock()
{
    # If lock file exists, check if stale.  If exists and is not stale, return TRUE
    # Else, create lock file and return FALSE.

    if (@symlink("/proc/" . getmypid(), LOCK_FILE) !== FALSE) # the @ in front of 'symlink' is to suppress the NOTICE you get if the LOCK_FILE exists
        return true;

    # link already exists
    # check if it's stale
    if (is_link(LOCK_FILE) && !is_dir(LOCK_FILE))
    {
        unlink(LOCK_FILE);
        # try to lock again
        return tryLock();
    }

    return false;
}
// Status file
$state = "/var/tmp/woonveilig.state";

// We roepen via cURL de triggers voor Domoticz aan d.m.v. het zetten of resetten van een virtuele switch.
function curl_download($Url){
    // is cURL installed yet?
    if (!function_exists('curl_init')){
        die('Sorry cURL is not installed!');
    }
    // OK cool - then let's create a new cURL resource handle
    $ch = curl_init();
    // Now set some options (most are optional)
    // Set URL to download
    curl_setopt($ch, CURLOPT_URL, $Url);
    // Set a referer
    curl_setopt($ch, CURLOPT_REFERER, "http://www.example.org/yay.htm");
    // User agent
    curl_setopt($ch, CURLOPT_USERAGENT, "MozillaXYZ/1.0");
    // Include header in result? (0 = yes, 1 = no)
    curl_setopt($ch, CURLOPT_HEADER, 0);
    // Should cURL return or print out the data? (true = return, false = print)
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Timeout in seconds
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    // Download the given URL, and return output
    $output = curl_exec($ch);
    // Close the cURL resource, and free system resources
    curl_close($ch);
    return $output;
}
function curl_slack($SlackEvent){
    // is cURL installed yet?
    if (!function_exists('curl_init')){
        die('Sorry cURL is not installed!');
    }
    // OK cool - then let's create a new cURL resource handle
    $ch = curl_init();
    // Now set some options (most are optional)
    // Set URL to download
    curl_setopt($ch, CURLOPT_URL, 'https://slack.com/api/chat.postMessage?token=xoxb-replaced-token&channel=woonveilig&text='.$SlackEvent);
    // Set a referer
    curl_setopt($ch, CURLOPT_REFERER, "http://www.example.org/yay.htm");
    // User agent
    curl_setopt($ch, CURLOPT_USERAGENT, "MozillaXYZ/1.0");
    // Include header in result? (0 = yes, 1 = no)
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array ('Accept: application/json'));
    // Should cURL return or print out the data? (true = return, false = print)
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Timeout in seconds
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    // Download the given URL, and return output
    $output = curl_exec($ch);
    // Close the cURL resource, and free system resources
    curl_close($ch);
    return $output;
}

/* main loop */
  while($c = socket_accept($sock))
  {
    /* wait for woonveilig system */
    socket_getpeername($c, $raddr, $rport);
    if ($cnt_twenty == 0)
    {
      syslog(LOG_INFO, "Received connection from $raddr:$rport");
    }
    // read buffer
    $buf = socket_read($c, 4192, PHP_BINARY_READ);

    if (false === $buf)
    {
      $err =  socket_strerror(socket_last_error($c));
      syslog(LOG_WARNING, "socket_read() failed: reason: $err");
    }  // fi
    else
    {
      /* The Woonveilig alarm system gives 20 times the following message [0258BF 1834010003408A0] */
      /* where 0258BF is the number from the Woonveilig system */
      /* where 1834010003408A0 is a event code from a configured alarm device */

      $d = date("Y-m-d H:i:s");
      $rec_event = explode (" ", urldecode($buf));
      socket_write($c, "[OK]\n", 6);
      $event = str_replace("]", "", $rec_event[1]);

      $cnt_twenty++;
      if ($event == $last_event)
      {
        if ($cnt_twenty == 20) // 20 times still the same value
        {
          $cnt_twenty = 0;
          $new_event = true;
          $last_event = 0;
        }
      }
      else
      {
        $last_event = $event;
      }
    } // esle

    /* Ok 20 time the same value is received */
    /* then handle event */
    if ($new_event == true)
    {
      $new_event = false; // reset event
      switch ($event)
      {
        case "18340000001xxxx" : $e = "Remote Control van oranje : Alarm aan"; $system = C_ARMED; break;
        case "18140000001xxxx" : $e = "Remote Control van oranje : Alarm uit"; $system = C_DISARMED; break;
        case "18340100034xxxx" : $e = "Webpaneel : Alarm aan"; $system = C_ARMED; break;
        case "18140100034xxxx" : $e = "Webpaneel : Alarm uit"; $system = C_DISARMED; break;
        case "18345600034xxxx" : $e = "Webpaneel : Alarm home"; $system = C_HOME; break;
        case "18160200000xxxx" : $e = "Alarm systeem in rust : $system "; break;
        case "18340700001xxxx" : $e = "Keypad : admin : Alarm aan"; $system = C_ARMED; break;
        case "18140700001xxxx" : $e = "Keypad : admin : Alarm uit"; $system = C_DISARMED; break;
        /*
        18113 Dit zijn de alarm meldingen zijn! 
        */
        case "18113000004xxxx" : $e = "Sensor schuur - Interior: ALARM"; $alarm = C_ON;  break;
        case "18113100006xxxx" : $e = "Achterdeur, Door Contact: Entry/Exit"; $alarm = C_ON ;  break;
        case "18113100003xxxx" : $e = "Voordeur, Door Contact   Entry/Exit"; $alarm = C_ON;  break;
        case "18113200005xxxx" : $e = "Sensor schuur - Interior: ALARM 2"; $alarm = C_ON;  break;
        // Dit zijn de Power switches
        case "181147000719DAB" : $e = "Power switch 1 / Zone 71, PSS    Out of order";break;
        case "181147000729EAC" : $e = "Power switch 2 / Zone 72, PSS    Out of order";break;
        case "18314700072B0AE" : $e = "Area 1, Zone 72, PSS:  Supervisor R";break;
        case "18314700071AFAD" : $e = "Area 1, Zone 71, PSS:  Supervisor R";break;
        // originele  alarm waarden verwijderd
        default:
           $e =  "[$d] - onbekend event : $event";
      } // switch

      syslog(LOG_INFO, "[Event] - [$d] [$e] is opgetreden");
	  touch($state);
      if ($alarm == C_ON)
      {
        syslog(LOG_INFO, "[Alarm opgetreden] - [$d] [$e] is opgetreden");
        print curl_download('http://127.0.0.1:8080/json.htm?type=command&param=switchlight&idx=121&switchcmd=On&level=0');
		$SlackAlarm = urlencode("[Alarm opgetreden] - [$d] [$e] is opgetreden");
		print curl_slack($SlackAlarm);
		$alarm = C_OFF;
		$system = C_ARMED;
      } // fi alarm
      else
      {
        if ($event != "181602000007C9F") // de rust melding is niet nodig die verschijnt om het halve uur
        {
    //      syslog(LOG_INFO, "[Event opgetreden] - [$d] [$e] is opgetreden");
    //		hier kunnen we het event wel doorsturen. 
			$SlackMsg = urlencode("[Event opgetreden] - [$d] [$e] is opgetreden");
			print curl_slack($SlackMsg);
        }
      } // else alarm
    } // fi new_event
   } // while
  socket_close($sock);
  closelog();
?>
