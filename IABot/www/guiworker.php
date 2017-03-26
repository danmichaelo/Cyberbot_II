<?php
set_include_path( get_include_path() . PATH_SEPARATOR . dirname( __FILE__ ) . DIRECTORY_SEPARATOR );
date_default_timezone_set( "UTC" );
ini_set( 'memory_limit', '128M' );
$_ = PHP_BINARY;

if( isset( $argv[2] ) ) $workerName = $argv[2];
else {
	die( "Error.  This is a CLI script.\n" );
}

if( function_exists( 'pcntl_exec' ) ) register_shutdown_function( function() {
	global $_, $argv; // note we need to reference globals inside a function
	// restart myself
	pcntl_exec( $_, $argv );
}
); else {
	echo "ERROR: pcntl_exec is not accessible.  The worker will die instead of restart.\n\n";
}

if( isset( $argv[3] ) ) {
	define( 'WIKIPEDIA', $argv[3] );
}

define( 'USEWEBINTERFACE', 0 );

require_once( 'loader.php' );
use Wikimedia\DeadlinkChecker\CheckIfDead;

$checkIfDead = new CheckIfDead();

$oauthObject = new OAuth();
$dbObject = new DB2();
echo "Begin run " . ( isset( $argv[1] ) ? ++$argv[1] : $argv[1] = 1 ) . "\n\n";

if( !API::botLogon() ) exit( 1 );

DB::checkDB();

$meSQL =
	"SELECT `user_id` FROM externallinks_user WHERE `user_name` = '" . USERNAME . "' AND `wiki` = '" . WIKIPEDIA . "';";
$res = $dbObject->queryDB( $meSQL );
if( $res ) {
	$userData = mysqli_fetch_assoc( $res );
	mysqli_free_result( $res );
	$me = new User( $dbObject, $oauthObject, $userData['user_id'], WIKIPEDIA );
} else {
	echo "ERROR: I don't exist on the interface for " . WIKIPEDIA . ".  Restarting after 1 minute.\n\n";
	sleep( 60 );
	exit( 3 );
}

$ch = curl_init();
curl_setopt( $ch, CURLOPT_COOKIEFILE, COOKIE );
curl_setopt( $ch, CURLOPT_COOKIEJAR, COOKIE );
curl_setopt( $ch, CURLOPT_USERAGENT, USERAGENT );
curl_setopt( $ch, CURLOPT_MAXCONNECTS, 100 );
curl_setopt( $ch, CURLOPT_MAXREDIRS, 10 );
curl_setopt( $ch, CURLOPT_ENCODING, 'gzip' );
curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
curl_setopt( $ch, CURLOPT_TIMEOUT, 100 );
curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 10 );
curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, 0 );
curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
curl_setopt( $ch, CURLOPT_SAFE_UPLOAD, true );

while( true ) {
	//Look for an existing task that's assigned to the worker.
	$sql =
		"SELECT * FROM externallinks_botqueue WHERE `queue_status` != 3 AND `queue_status` != 2 AND `assigned_worker` = '" .
		$dbObject->sanitize( $workerName ) . "';";
	$res = $dbObject->queryDB( $sql );
	if( mysqli_num_rows( $res ) < 1 ) {
		//Use an Update statement to grab a task.  This lets us avoid race conditions and lock timeouts.
		$sql =
			"UPDATE externallinks_botqueue SET `queue_id` = @id := `queue_id`, `wiki` = @wiki := `wiki`, `queue_user` = @user := `queue_user`, `status_timestamp` = CURRENT_TIMESTAMP, `queue_status` = @status := 1, `queue_pages` = @pages := `queue_pages`, `assigned_worker` = '" .
			$dbObject->sanitize( $workerName ) .
			"', `worker_finished` = @progress := `worker_finished`, `worker_target` = @total := `worker_target`, `run_stats` = @stats := `run_stats` WHERE `queue_status` = 0 LIMIT 1;";
		if( $dbObject->queryDB( $sql ) ) {
			if( $dbObject->getAffectedRows() > 0 ) {
				$sql =
					"SELECT @id as queue_id, @wiki as wiki, @user as queue_user, @status as queue_status, @pages as queue_pages, @progress as worker_finished, @total as worker_target, @stats as run_stats;";
				$res2 = $dbObject->queryDB( $sql );
				if( $jobData = mysqli_fetch_assoc( $res2 ) ) {
					if( WIKIPEDIA != $jobData['wiki'] ) {
						echo "Restarting worker... Worker is switching over to " . $jobData['wiki'] . "\n\n";
						$argv[3] = $jobData['wiki'];
						exit( -100 );
					}
				} else {
					echo "Unable to fetch reserved job.  Restarting...\n\n";
					exit( 1 );
				}
			} else {
				echo "No jobs to work on at the moment.  Sleeping for 1 minute.\n\n";
				sleep( 60 );
				continue;
			}
		} else {
			echo "No jobs to work on at the moment.  Sleeping for 1 minute.\n\n";
			sleep( 60 );
			continue;
		}
	} else {
		$jobData = mysqli_fetch_assoc( $res );
		if( $jobData['queue_status'] == 0 ) {
			$updateSQL = "UPDATE externallinks_botqueue SET `queue_status` = 1 WHERE `queue_status` != 3 AND `queue_status` != 2 AND `assigned_worker` = '" .
			             $dbObject->sanitize( $workerName ) . "';";
			$dbObject->queryDB( $updateSQL );
			$jobData['queue_status'] = 1;
		}
		mysqli_free_result( $res );
	}

	$config = API::fetchConfiguration();

	if( isset( $overrideConfig ) && is_array( $overrideConfig ) ) {
		foreach( $overrideConfig as $variable => $value ) {
			if( isset( $config[$variable] ) ) $config[$variable] = $value;
		}
	}

	API::escapeTags( $config );

	$jobID = $jobData['queue_id'];
	$userSQL =
		"SELECT `user_id` FROM externallinks_user WHERE `user_link_id` = " . $jobData['queue_user'] . " AND `wiki` = '" .
		$dbObject->sanitize( WIKIPEDIA ) . "';";
	if( $userRes = $dbObject->queryDB( $userSQL ) ) {
		$userData = mysqli_fetch_assoc( $userRes );
		mysqli_free_result( $userRes );
		$userObject = new User( $dbObject, $oauthObject, $userData['user_id'], WIKIPEDIA );
		if( !defined( 'REQUESTEDBY' ) ) define( 'REQUESTEDBY', $userObject->getUsername() );
		elseif( REQUESTEDBY != $userObject->getUsername() ) {
			echo "Need to reassign worker to different user.  Restarting...\n\n";
			exit( -400 );
		}
	} else {
		echo "User data error detected.  Possible DB fault.  Restarting...\n\n";
		exit( 2 );
	}
	$pages = unserialize( $jobData['queue_pages'] );
	$runStats = unserialize( $jobData['run_stats'] );
	$progressCount = $jobData['worker_finished'];
	$progressFinal = $jobData['worker_target'];
	$runStatus = $jobData['queue_status'];

	if( !is_array( $pages ) || !is_array( $runStats ) ) {
		if( !is_array( $pages ) ) echo "Page list is corrupted.  Killing job and moving on...\n\n";
		else echo "Run stats is corrupted.  Killing job and moving on...\n\n";
		$updateSQL = "UPDATE externallinks_botqueue SET `queue_status` = 3 WHERE `queue_id` = $jobID;";
		if( $userObject->hasEmail() && $userObject->getEmailBQKilled() ) {
			$mailObject = new HTMLLoader( "emailmain", $userObject->getLanguage() );
			$mailbodysubject = new HTMLLoader( "{{{bqmailjobkillmsg}}}", $userObject->getLanguage() );
			$mailbodysubject->assignAfterElement( "logobject", $jobID );
			$mailbodysubject->assignAfterElement( "joburl", ROOTURL . "index.php?page=viewjob&id={$jobID}"
			);
			$mailbodysubject->finalize();
			$mailObject->assignAfterElement( "rooturl", ROOTURL );
			$mailObject->assignAfterElement( "joburl", ROOTURL . "index.php?page=viewjob&id={$jobID}" );
			$mailObject->assignElement( "body", $mailbodysubject->getLoadedTemplate() );
			$mailObject->finalize();
			mailHTML( $userObject->getEmail(), preg_replace( '/\<.*?\>/i', "", $mailbodysubject->getLoadedTemplate() ), $mailObject->getLoadedTemplate()
			);
		}
		if( $dbObject->queryDB( $updateSQL ) ) {
			$dbObject->insertLogEntry( WIKIPEDIA, WIKIPEDIA, "bqchangestatus", "kill", $jobID, "",
			                           $me->getUserLinkID(), $runStatus, 3, "{{{botdatacorrupted}}}"
			);
			$me->setLastAction( time() );
		}
		continue;
	}

	foreach( $pages as $id => $page ) {
		$runStatus = $jobData['queue_status'];
		switch( $runStatus ) {
			case 0:
			case 1:
				$runStatus = 1;
				break;
			case 4:
				echo "Job suspended.  Sleeping for 1 minute...\n\n";
				sleep( 60 );
				break;
			case 3:
				echo "Job killed, moving on to another job...\n\n";
				break;
		}
		if( $runStatus >= 3 ) break;

		if( $page['status'] != "wait" ) continue;

		$get = [
			'action' => 'query',
			'titles' => $page['title'],
			'format' => 'php'
		];
		$get = http_build_query( $get );
		curl_setopt( $ch, CURLOPT_URL, API . "?$get" );
		curl_setopt( $ch, CURLOPT_HTTPHEADER,
		             [ API::generateOAuthHeader( 'GET', API . "?$get" ) ]
		);
		curl_setopt( $ch, CURLOPT_HTTPGET, 1 );
		curl_setopt( $ch, CURLOPT_POST, 0 );
		$data = curl_exec( $ch );
		$data = unserialize( $data );

		if( isset( $data['query']['pages'] ) ) {
			foreach( $data['query']['pages'] as $tpage ) {
				if( isset( $tpage['missing'] ) ) {
					$pages[$id]['status'] = "skipped";
				} elseif( isset( $tpage['pageid'] ) ) {
					break;
				} else {
					echo "API error encounter during page validation.  Waiting 1 minute and restarting.\n\n";
					sleep( 60 );
					exit( 4 );
				}
			}
		}

		$commObject = new API( $tpage['title'], $tpage['pageid'], $config );
		$tmp = PARSERCLASS;
		$parser = new $tmp( $commObject );
		$stats = $parser->analyzePage();
		$commObject->closeResources();
		$parser = $commObject = null;

		$progressCount++;
		$pages[$id]['status'] = "complete";

		if( $stats['pagemodified'] === true ) $runStats['pagesModified']++;
		$runStats['linksanalyzed'] += $stats['linksanalyzed'];
		$runStats['linksarchived'] += $stats['linksarchived'];
		$runStats['linksrescued'] += $stats['linksrescued'];
		$runStats['linkstagged'] += $stats['linkstagged'];

		$updateSQL =
			"UPDATE externallinks_botqueue SET `status_timestamp` = CURRENT_TIMESTAMP, `queue_status` = @status := `queue_status`, `queue_pages` = '" .
			$dbObject->sanitize( serialize( $pages ) ) . "', `assigned_worker` = '" .
			$dbObject->sanitize( $workerName ) .
			"', `worker_finished` = $progressCount, `run_stats` = '" . $dbObject->sanitize( serialize( $runStats ) ) .
			"' WHERE `queue_id` = $jobID;";
		if( $dbObject->queryDB( $updateSQL ) ) {
			$sql = "SELECT @status as queue_status;";
			if( $jobRes = $dbObject->queryDB( $sql ) ) {
				$jobData = mysqli_fetch_assoc( $jobRes );
				mysqli_free_result( $jobRes );
			}
		}
	}

	switch( $runStatus ) {
		case 3:
		case 4:
			continue;
	}

	if( $progressCount == $progressFinal ) {
		echo "Finished job $jobID\n\n";
		$updateSQL = "UPDATE externallinks_botqueue SET `queue_status` = 2 WHERE `queue_id` = $jobID;";
		if( $userObject->hasEmail() && $userObject->getEmailBQComplete() ) {
			$mailObject = new HTMLLoader( "emailmain", $userObject->getLanguage() );
			$mailbodysubject = new HTMLLoader( "{{{bqmailjobcompletemsg}}}", $userObject->getLanguage() );
			$mailbodysubject->assignAfterElement( "logobject", $jobID );
			$mailbodysubject->assignAfterElement( "joburl", ROOTURL . "index.php?page=viewjob&id={$jobID}"
			);
			$mailbodysubject->finalize();
			$mailObject->assignAfterElement( "rooturl", ROOTURL );
			$mailObject->assignAfterElement( "joburl", ROOTURL . "index.php?page=viewjob&id={$jobID}" );
			$mailObject->assignElement( "body", $mailbodysubject->getLoadedTemplate() );
			$mailObject->finalize();
			mailHTML( $userObject->getEmail(), preg_replace( '/\<.*?\>/i', "", $mailbodysubject->getLoadedTemplate() ), $mailObject->getLoadedTemplate()
			);
		}
		if( $dbObject->queryDB( $updateSQL ) ) {
			$dbObject->insertLogEntry( WIKIPEDIA, WIKIPEDIA, "bqchangestatus", "finish", $jobID, "",
			                           $me->getUserLinkID(), $runStatus, 2, ""
			);
			$me->setLastAction( time() );
		}
	}
}