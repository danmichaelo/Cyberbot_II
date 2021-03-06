<?php

/*
	Copyright (c) 2015-2017, Maximilian Doerr

	This file is part of IABot's Framework.

	IABot is free software: you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	IABot is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with IABot.  If not, see <http://www.gnu.org/licenses/>.
*/

/**
 * @file
 * svwikiParser object
 * @author Maximilian Doerr (Cyberpower678)
 * @license https://www.gnu.org/licenses/gpl.txt
 * @copyright Copyright (c) 2015-2017, Maximilian Doerr
 */

/**
 * svwikiParser class
 * Extension of the master parser class specifically for en.wikipedia.org
 * @author Maximilian Doerr (Cyberpower678)
 * @license https://www.gnu.org/licenses/gpl.txt
 * @copyright Copyright (c) 2015-2017, Maximilian Doerr
 */
class svwikiParser extends Parser {

	/**
	 * Rescue a link
	 *
	 * @param array $link Link being analyzed
	 * @param array $modifiedLinks Links that were modified
	 * @param array $temp Cached result value from archive retrieval function
	 *
	 * @access protected
	 * @abstract
	 * @author Maximilian Doerr (Cyberpower678)
	 * @license https://www.gnu.org/licenses/gpl.txt
	 * @copyright Copyright (c) 2015-2017, Maximilian Doerr
	 * @return void
	 */
	protected function rescueLink( &$link, &$modifiedLinks, &$temp, $tid, $id ) {
		//The initial assumption is that we are adding an archive to a URL.
		$modifiedLinks["$tid:$id"]['type'] = "addarchive";
		$modifiedLinks["$tid:$id"]['link'] = $link['url'];
		$modifiedLinks["$tid:$id"]['newarchive'] = $temp['archive_url'];

		//The newdata index is all the data being injected into the link array.  This allows for the preservation of the old data for easier manipulation and maintenance.
		$link['newdata']['has_archive'] = true;
		$link['newdata']['archive_url'] = $temp['archive_url'];
		if( isset( $link['fragment'] ) && !is_null( $link['fragment'] ) ) $link['newdata']['archive_url'] .= "#" .
		                                                                                                     $link['fragment'];
		elseif( isset( $link['archive_fragment'] ) &&
		        !is_null( $link['archive_fragment'] )
		) $link['newdata']['archive_url'] .= "#" .
		                                     $link['archive_fragment'];
		$link['newdata']['archive_time'] = $temp['archive_time'];
		//If we are dealing with an external link, or a stray archive template, then...
		if( $link['link_type'] == "link" || $link['link_type'] == "stray" ) {
			//If it is plain URL with no embedded text if it's in brackets, or is a stray archive template, then convert it to a citation template.
			//Else attach an archive template to it.
			if( trim( $link['link_string'], " []" ) == $link['url'] || $link['link_type'] == "stray" ) {
				$link['newdata']['archive_type'] = "parameter";
				$link['newdata']['link_template']['name'] = "Webbref";
				//Correct mixed encoding in URLs by fulling decoding everything, and then encoding the query segment.
				$link['newdata']['link_template']['parameters']['url'] =
					str_replace( parse_url( $link['url'], PHP_URL_QUERY ),
					             urlencode( urldecode( parse_url( $link['url'], PHP_URL_QUERY ) ) ), $link['url']
					);
				//If we are dealing with a stray archive template, try and copy the contents of its title parameter to the new citation template.
				if( $link['link_type'] == "stray" && ( isset( $link['archive_template']['parameters']['titel'] ) ||
				                                       isset( $link['archive_template']['parameters']['title'] ) )
				) {
					if( isset( $link['archive_template']['parameters']['titel'] ) )
						$link['newdata']['link_template']['parameters']['titel'] =
							$link['archive_template']['parameters']['titel'];
					else $link['newdata']['link_template']['parameters']['titel'] =
						$link['archive_template']['parameters']['title'];
				} else $link['newdata']['link_template']['parameters']['titel'] = "Arkiverade kopian";
				//We need to define the access date.
				$link['newdata']['link_template']['parameters']['hämtdatum'] =
					strtolower( self::strftime( $this->retrieveDateFormat( true ), $link['access_time'] ) );
				//Let this function handle the rest.
				$this->generateNewCitationTemplate( $link, $temp );

				//If any invalid flags were raised, then we fixed a source rather than added an archive to it.
				if( $link['has_archive'] === true && $link['archive_type'] == "invalid" ) {
					if( !isset( $link['template_url'] ) ) $link['newdata']['link_template']['parameters']['url'] =
						$link['url'];
					else $link['newdata']['link_template']['parameters']['url'] = $link['template_url'];
					$modifiedLinks["$tid:$id"]['type'] = "fix";
				}
				//Force change the link type to a template.  This part is not within the scope of the array merger, as it's too high level.
				$link['link_type'] = "template";
			} else {
				if( $this->generateNewArchiveTemplate( $link, $temp ) ) {
					$link['newdata']['archive_type'] = "template";
					$link['newdata']['tagged_dead'] = false;
				}
			}
		} elseif( $link['link_type'] == "template" ) {
			//Since we already have a template, let this function make the needed modifications.
			$this->generateNewCitationTemplate( $link, $temp );

			//If any invalid flags were raised, then we fixed a source rather than added an archive to it.
			if( ( $link['has_archive'] === true && $link['archive_type'] == "invalid" ) ||
			    ( $link['tagged_dead'] === true && $link['tag_type'] == "invalid" )
			) {
				if( !isset( $link['template_url'] ) ) $link['newdata']['link_template']['parameters']['url'] =
					$link['url'];
				else $link['newdata']['link_template']['parameters']['url'] = $link['template_url'];
				$modifiedLinks["$tid:$id"]['type'] = "fix";
			}
		}
		//If any invalid flags were raised, then we fixed a source rather than added an archive to it.
		if( isset( $link['convert_archive_url'] ) ||
		    ( $link['has_archive'] === true && $link['archive_type'] == "invalid" ) ||
		    ( $link['tagged_dead'] === true && $link['tag_type'] == "invalid" )
		) {
			$modifiedLinks["$tid:$id"]['type'] = "fix";
		}
		//If we ended up changing the archive URL despite invalid flags, we should mention that change instead.
		if( $link['has_archive'] === true && $link['archive_url'] != $temp['archive_url'] &&
		    !isset( $link['convert_archive_url'] )
		) {
			$modifiedLinks["$tid:$id"]['type'] = "modifyarchive";
			$modifiedLinks["$tid:$id"]['oldarchive'] = $link['archive_url'];
		}
		unset( $temp );
	}

	/**
	 * Get page date formatting standard
	 *
	 * @param bool|string $default Return default format, or return supplied date format of timestamp, provided a page
	 *     tag doesn't override it.
	 *
	 * @access protected
	 * @abstract
	 * @author Maximilian Doerr (Cyberpower678)
	 * @license https://www.gnu.org/licenses/gpl.txt
	 * @copyright Copyright (c) 2015-2017, Maximilian Doerr
	 * @return string Format to be fed in time()
	 */
	protected function retrieveDateFormat( $default = false ) {
		if( !is_bool( $default ) &&
		    preg_match( '/\d\d? (?:Januari|January|Februari|February|Mars|March|April|Maj|May|Juni|June|Juli|July|Augusti|August|September|Oktober|October|November|December) \d{4}/i',
		                $default
		    )
		) return '%-e %B %Y';
		elseif( !is_bool( $default ) &&
		        preg_match( '/(?:Januari|January|Februari|February|Mars|March|April|Maj|May|Juni|June|Juli|July|Augusti|August|September|Oktober|October|November|December) \d\d?\, \d{4}/i',
		                    $default
		        )
		) return '%B %-e, %Y';
		else return '%Y-%m-%d';
	}

	/**
	 * Generates an appropriate citation template without altering existing parameters.
	 *
	 * @access protected
	 * @abstract
	 * @author Maximilian Doerr (Cyberpower678)
	 * @license https://www.gnu.org/licenses/gpl.txt
	 * @copyright Copyright (c) 2015-2017, Maximilian Doerr
	 *
	 * @param $link Current link being modified
	 * @param $temp Current temp result from fetchResponse
	 *
	 * @return bool If successful or not
	 */
	protected function generateNewCitationTemplate( &$link, &$temp ) {
		$link['newdata']['archive_type'] = "parameter";
		//We need to flag it as dead so the string generator knows how to behave, when assigning the deadurl parameter.
		if( $link['tagged_dead'] === true || $link['is_dead'] === true ) $link['newdata']['tagged_dead'] = true;
		else $link['newdata']['tagged_dead'] = false;
		$link['newdata']['tag_type'] = "parameter";
		//Set the archive URL
		if( isset( $link['link_template']['parameters']['datum'] ) ||
		    isset( $link['link_template']['parameters']['hämtdatum'] ) ||
		    isset( $link['newdata']['link_template']['parameters']['hämtdatum'] ) ||
		    isset( $link['link_template']['parameters']['arkivurl'] ) ||
		    isset( $link['link_template']['parameters']['titel'] ) ||
		    isset( $link['link_template']['parameters']['författare'] ) ||
		    isset( $link['link_template']['parameters']['efternamn'] ) ||
		    isset( $link['link_template']['parameters']['förnamn'] ) ||
		    isset( $link['link_template']['parameters']['författarlänk'] ) ||
		    isset( $link['link_template']['parameters']['efternamn2'] ) ||
		    isset( $link['link_template']['parameters']['förnamn2'] ) ||
		    isset( $link['link_template']['parameters']['år'] ) ||
		    isset( $link['link_template']['parameters']['månad'] ) ||
		    isset( $link['link_template']['parameters']['verk'] ) ||
		    isset( $link['link_template']['parameters']['utgivare'] ) ||
		    isset( $link['link_template']['parameters']['språk'] ) ||
		    isset( $link['link_template']['parameters']['citat'] )
		)
			$link['newdata']['link_template']['parameters']['arkivurl'] = $temp['archive_url'];
		else $link['newdata']['link_template']['parameters']['archiveurl'] = $temp['archive_url'];

		//Set the archive date
		if( isset( $link['newdata']['link_template']['parameters']['arkivurl'] ) )
			$link['newdata']['link_template']['parameters']['arkivdatum'] =
				strtolower( self::strftime( $this->retrieveDateFormat( $link['string'] ), $temp['archive_time'] ) );
		else $link['newdata']['link_template']['parameters']['archivedate'] =
			strtolower( self::strftime( $this->retrieveDateFormat( $link['string'] ), $temp['archive_time'] ) );
	}

	/**
	 * Generates an appropriate archive template if it can.
	 *
	 * @access protected
	 * @abstract
	 * @author Maximilian Doerr (Cyberpower678)
	 * @license https://www.gnu.org/licenses/gpl.txt
	 * @copyright Copyright (c) 2015-2017, Maximilian Doerr
	 *
	 * @param $link Current link being modified
	 * @param $temp Current temp result from fetchResponse
	 *
	 * @return bool If successful or not
	 */
	protected function generateNewArchiveTemplate( &$link, &$temp ) {
		//We need the archive host, to pick the right template.
		if( !isset( $link['newdata']['archive_host'] ) ) $link['newdata']['archive_host'] =
			$this->getArchiveHost( $temp['archive_url'] );
		//If the archive template is being used improperly, delete the parameters, and start fresh.
		if( $link['has_archive'] === true &&
		    $link['archive_type'] == "invalid"
		) unset( $link['archive_template']['parameters'] );
		switch( $link['newdata']['archive_host'] ) {
			case "wayback":
				$link['newdata']['archive_template']['name'] = "Wayback";
				$link['newdata']['archive_template']['parameters']['url'] = $link['url'];
				if( $temp['archive_time'] != 0 ) $link['newdata']['archive_template']['parameters']['date'] =
					date( 'YmdHis', $temp['archive_time'] );
				else $link['newdata']['archive_template']['parameters']['date'] = "*";
				break;
			case "webcite":
				$link['newdata']['archive_template']['name'] = "WebCite";
				$link['newdata']['archive_template']['parameters']['url'] = $temp['archive_url'];
				if( $temp['archive_time'] != 0 ) $link['newdata']['archive_template']['parameters']['date'] =
					date( 'YmdHis', $temp['archive_time'] );
				switch( $this->retrieveDateFormat() ) {
					case 'F j Y':
						$link['newdata']['archive_template']['parameters']['dateformat'] = "mdy";
						break;
					case 'j F Y':
						$link['newdata']['archive_template']['parameters']['dateformat'] = "dmy";
						break;
					default:
						$link['newdata']['archive_template']['parameters']['dateformat'] = "iso";
						break;
				}
				break;
			default:
				return false;
		}

		return true;
	}

	/**
	 * Modify link that can't be rescued
	 *
	 * @param array $link Link being analyzed
	 * @param array $modifiedLinks Links modified array
	 *
	 * @access protected
	 * @abstract
	 * @author Maximilian Doerr (Cyberpower678)
	 * @license https://www.gnu.org/licenses/gpl.txt
	 * @copyright Copyright (c) 2015-2017, Maximilian Doerr
	 * @return void
	 */
	protected function noRescueLink( &$link, &$modifiedLinks, $tid, $id ) {
		$modifiedLinks["$tid:$id"]['type'] = "tagged";
		$modifiedLinks["$tid:$id"]['link'] = $link['url'];
		$link['newdata']['tag_type'] = "template";
		$link['newdata']['tag_template']['name'] = "död länk";
		$link['newdata']['tag_template']['parameters']['datum'] = date( 'Y-m' );
		$link['newdata']['tag_template']['parameters']['bot'] = USERNAME;
	}

	/**
	 * Analyze the citation template
	 *
	 * @param array $returnArray Array being generated in master function
	 * @param string $params Citation template regex match breakdown
	 *
	 * @access protected
	 * @abstract
	 * @author Maximilian Doerr (Cyberpower678)
	 * @license https://www.gnu.org/licenses/gpl.txt
	 * @copyright Copyright (c) 2015-2017, Maximilian Doerr
	 * @return void
	 */
	protected function analyzeCitation( &$returnArray, &$params ) {
		$returnArray['tagged_dead'] = false;
		$returnArray['link_type'] = "template";
		$returnArray['link_template'] = [];
		$returnArray['link_template']['parameters'] = $this->getTemplateParameters( $params[2] );
		$returnArray['link_template']['name'] = str_replace( "{{", "", $params[1] );
		$returnArray['link_template']['string'] = $params[0];
		//If we can't get a URL, then this is useless.  Discontinue analysis and move on.
		if( isset( $returnArray['link_template']['parameters']['url'] ) &&
		    !empty( $returnArray['link_template']['parameters']['url'] )
		) $returnArray['original_url'] = $returnArray['url'] = $this->filterText( htmlspecialchars_decode( $returnArray['link_template']['parameters']['url'] ) );
		elseif( isset( $returnArray['link_template']['parameters']['website'] ) &&
		        !empty( $returnArray['link_template']['parameters']['website'] )
		) $returnArray['original_url'] = $returnArray['url'] = $this->filterText( htmlspecialchars_decode( $returnArray['link_template']['parameters']['website'] ) );
		else return true;
		//Fetch the access date.  Use the wikitext resolver in case a date template is being used.
		if( isset( $returnArray['link_template']['parameters']['accessdate'] ) &&
		    !empty( $returnArray['link_template']['parameters']['accessdate'] )
		) {
			$time = self::strtotime( $returnArray['link_template']['parameters']['accessdate'] );
			if( $time === false ) $time =
				self::strtotime( API::resolveWikitext( $returnArray['link_template']['parameters']['accessdate'] ) );
			if( $time === false ) $time = "x";
			$returnArray['access_time'] = $time;
		} elseif( isset( $returnArray['link_template']['parameters']['hämtdatum'] ) &&
		          !empty( $returnArray['link_template']['parameters']['hämtdatum'] )
		) {
			$time = self::strtotime( $returnArray['link_template']['parameters']['hämtdatum'] );
			if( $time === false ) $time =
				self::strtotime( API::resolveWikitext( $returnArray['link_template']['parameters']['hämtdatum'] ) );
			if( $time === false ) $time = "x";
			$returnArray['access_time'] = $time;
		} elseif( isset( $returnArray['link_template']['parameters']['datum'] ) &&
		          !empty( $returnArray['link_template']['parameters']['datum'] )
		) {
			$time = self::strtotime( $returnArray['link_template']['parameters']['datum'] );
			if( $time === false ) $time =
				self::strtotime( API::resolveWikitext( $returnArray['link_template']['parameters']['datum'] ) );
			if( $time === false ) $time = "x";
			$returnArray['access_time'] = $time;
		} else $returnArray['access_time'] = "x";
		//Check for the presence of an archive URL
		if( isset( $returnArray['link_template']['parameters']['archiveurl'] ) &&
		    !empty( $returnArray['link_template']['parameters']['archiveurl'] )
		) $returnArray['archive_url'] = $this->filterText( htmlspecialchars_decode( $returnArray['link_template']['parameters']['archiveurl'] ) );
		if( isset( $returnArray['link_template']['parameters']['arkivdatum'] ) &&
		    !empty( $returnArray['link_template']['parameters']['arkivurl'] )
		) $returnArray['archive_url'] = $this->filterText( htmlspecialchars_decode( $returnArray['link_template']['parameters']['arkivurl'] ) );
		if( ( ( isset( $returnArray['link_template']['parameters']['archiveurl'] ) &&
		        !empty( $returnArray['link_template']['parameters']['archiveurl'] ) ) ||
		      ( isset( $returnArray['link_template']['parameters']['arkivurl'] ) &&
		        !empty( $returnArray['link_template']['parameters']['arkivurl'] ) ) ) &&
		    API::isArchive( $returnArray['archive_url'], $returnArray )
		) {
			$returnArray['archive_type'] = "parameter";
			$returnArray['has_archive'] = true;
			$returnArray['is_archive'] = false;
		}

		//Using an archive URL in the url field is not correct.  Flag as invalid usage if the URL is an archive.
		if( API::isArchive( $returnArray['original_url'], $returnArray ) ) {
			$returnArray['has_archive'] = true;
			$returnArray['is_archive'] = true;
			$returnArray['archive_type'] = "invalid";

			if( !isset( $returnArray['link_template']['parameters']['accessdate'] ) &&
			    !isset( $returnArray['link_template']['parameters']['hämtdatum'] ) && $returnArray['access_time'] != "x"
			) $returnArray['access_time'] = $returnArray['archive_time'];
			else {
				if( isset( $returnArray['link_template']['parameters']['accessdate'] ) &&
				    !empty( $returnArray['link_template']['parameters']['accessdate'] ) &&
				    $returnArray['access_time'] != "x"
				) $returnArray['access_time'] =
					self::strtotime( $returnArray['link_template']['parameters']['accessdate'] );
				elseif( isset( $returnArray['link_template']['parameters']['hämtdatum'] ) &&
				        !empty( $returnArray['link_template']['parameters']['hämtdatum'] ) &&
				        $returnArray['access_time'] != "x"
				) $returnArray['access_time'] =
					self::strtotime( $returnArray['link_template']['parameters']['hämtdatum'] );
				else $returnArray['access_time'] = "x";
			}
		}

		//Check if this URL is lingering behind a paywall.
		if( isset( $returnArray['link_template']['parameters']['registration'] ) ||
		    isset( $returnArray['link_template']['parameters']['subscription'] )
		) {
			$returnArray['tagged_paywall'] = true;
		}
	}

	/**
	 * Analyze the remainder string
	 *
	 * @param array $returnArray Array being generated in master function
	 * @param string $remainder Remainder string
	 *
	 * @access protected
	 * @abstract
	 * @author Maximilian Doerr (Cyberpower678)
	 * @license https://www.gnu.org/licenses/gpl.txt
	 * @copyright Copyright (c) 2015-2017, Maximilian Doerr
	 * @return void
	 */
	protected function analyzeRemainder( &$returnArray, &$remainder ) {
		//If there's an archive tag, then...
		if( preg_match( $this->fetchTemplateRegex( $this->commObject->config['archive_tags'] ), $remainder, $params2
		) ) {
			$returnArray['archive_type'] = "template";
			$returnArray['archive_template'] = [];
			$returnArray['archive_template']['parameters'] = $this->getTemplateParameters( $params2[2] );
			$returnArray['archive_template']['name'] = str_replace( "{{", "", $params2[1] );
			$returnArray['archive_template']['string'] = $params2[0];

			//If there already is an archive in this source, it's means there's an archive template attached to a citation template.  That's needless confusion when sourcing.
			if( $returnArray['link_type'] == "template" && $returnArray['has_archive'] === false ) {
				$returnArray['archive_type'] = "invalid";
				$returnArray['tagged_dead'] = true;
				$returnArray['tag_type'] = "implied";
			}

			$returnArray['has_archive'] = true;

			//If there is a wayback tag present, process it
			if( preg_match( $this->fetchTemplateRegex( $this->commObject->config['archive1_tags'] ), $remainder,
			                $params2
			) ) {
				$returnArray['archive_host'] = "wayback";

				//Look for the URL.  If there isn't any found, the template is being used wrong.
				if( isset( $returnArray['archive_template']['parameters']['url'] ) ) {
					$url = htmlspecialchars_decode( $returnArray['archive_template']['parameters']['url'] );
				} elseif( isset( $returnArray['archive_template']['parameters'][1] ) ) {
					$url = htmlspecialchars_decode( $returnArray['archive_template']['parameters'][1] );
				} elseif( isset( $returnArray['archive_template']['parameters']['site'] ) ) {
					$url = htmlspecialchars_decode( $returnArray['archive_template']['parameters']['site'] );
				} else {
					$returnArray['archive_url'] = "x";
					$returnArray['archive_type'] = "invalid";
				}

				//Look for archive timestamp.  If there isn't any, then it's not pointing a snapshot, which makes it harder for the reader and other editors.
				if( isset( $returnArray['archive_template']['parameters']['date'] ) ) {
					$returnArray['archive_time'] =
						self::strtotime( $returnArray['archive_template']['parameters']['date'] );
					$returnArray['archive_url'] =
						"https://web.archive.org/web/{$returnArray['archive_template']['parameters']['date']}/$url";
				} else {
					$returnArray['archive_time'] = "x";
					$returnArray['archive_url'] = "https://web.archive.org/web/*/$url";
					$returnArray['archive_type'] = "invalid";
				}

				//If the original URL isn't present, then we are dealing with a stray archive template.
				if( !isset( $returnArray['url'] ) ) {
					$returnArray['archive_type'] = "invalid";
					$returnArray['url'] = $url;
					$returnArray['link_type'] = "stray";
					$returnArray['is_archive'] = true;
				}

				//Check for a malformation or template misuse.  The URL field needs the original URL, not the archive URL.
				if( $returnArray['archive_url'] == "x" || strpos( $url, "archive.org" ) !== false ) {
					if( preg_match( '/archive\.org\/(web\/)?(\d*?|\*)\/(\S*)\s?/i', $url, $params3 ) ) {
						$returnArray['archive_type'] = "invalid";
						if( $params3[2] != "*" ) $returnArray['archive_time'] = strtotime( $params3[2] );
						else $returnArray['archive_time'] = "x";
						$returnArray['archive_url'] = "https://web." . $params3[0];
					} else {
						$returnArray['archive_type'] = "invalid";
					}
				}
			} //If there is a webcite tag present, process it
			elseif( preg_match( $this->fetchTemplateRegex( $this->commObject->config['archive2_tags'] ), $remainder,
			                    $params2
			) ) {
				$returnArray['archive_host'] = "webcite";
				//Look for the URL.  If there isn't any found, the template is being used wrong.
				if( isset( $returnArray['archive_template']['parameters']['url'] ) ) {
					$returnArray['archive_url'] = htmlspecialchars_decode( $returnArray['archive_template']['parameters']['url'] );
				} elseif( isset( $returnArray['archive_template']['parameters'][1] ) ) {
					$returnArray['archive_url'] = htmlspecialchars_decode( $returnArray['archive_template']['parameters'][1] );
				} else {
					$returnArray['archive_url'] = "x";
					$returnArray['archive_type'] = "invalid";
				}

				//Look for the archive timestamp.  Since the Webcite archives use a unique URL for each snapshot, a missing date stamp does not mean invalid usage.
				if( isset( $returnArray['archive_template']['parameters']['date'] ) ) {
					$returnArray['archive_time'] =
						self::strtotime( $returnArray['archive_template']['parameters']['date'] );
				} else {
					$returnArray['archive_time'] = "x";
				}

				//If the original URL isn't present, then we are dealing with a stray archive template.
				if( !isset( $returnArray['url'] ) ) {
					//resolve the archive to the original URL
					API::isArchive( $returnArray['archive_url'], $returnArray );
					$returnArray['archive_type'] = "invalid";
					$returnArray['link_type'] = "stray";
					$returnArray['is_archive'] = true;
				}
			}

			//If we have multiple archives, we can't handle these correctly, so remove any force markers that may force the editing of the citations.
			if( $returnArray['link_type'] == "template" && $returnArray['has_archive'] === true &&
			    $returnArray['archive_type'] == "template"
			) {
				unset( $returnArray['convert_archive_url'] );
				unset( $returnArray['force_when_dead'] );
				unset( $returnArray['force'] );
				unset( $returnArray['force_when_alive'] );
			}
		}

		if( preg_match( $this->fetchTemplateRegex( $this->commObject->config['deadlink_tags'] ), $remainder, $params2
		) ) {
			$returnArray['tagged_dead'] = true;
			$returnArray['tag_type'] = "template";
			if( isset( $params2[2] ) ) $returnArray['tag_template']['parameters'] =
				$this->getTemplateParameters( $params2[2] );
			else $returnArray['tag_template']['parameters'] = [];
			$returnArray['tag_template']['name'] = str_replace( "{{", "", $params2[1] );
			$returnArray['tag_template']['string'] = $params2[0];
		}
	}

	/**
	 * Return a unix timestamp allowing for international support through abstract functions.
	 *
	 * @param $string A timestamp
	 *
	 * @access public
	 * @static
	 * @author Maximilian Doerr (Cyberpower678)
	 * @license https://www.gnu.org/licenses/gpl.txt
	 * @copyright Copyright (c) 2015-2017, Maximilian Doerr
	 * @return int|false A unix timestamp or false on failure.
	 */
	public static function strtotime( $string ) {
		$string = preg_replace( '/Januari/i', "January", $string );
		$string = preg_replace( '/Februari/i', "February", $string );
		$string = preg_replace( '/Mars/i', "March", $string );
		$string = preg_replace( '/April/i', "April", $string );
		$string = preg_replace( '/Maj/i', "May", $string );
		$string = preg_replace( '/Juni/i', "June", $string );
		$string = preg_replace( '/Juli/i', "July", $string );
		$string = preg_replace( '/Augusti/i', "August", $string );
		$string = preg_replace( '/September/i', "September", $string );
		$string = preg_replace( '/Oktober/i', "October", $string );
		$string = preg_replace( '/November/i', "November", $string );
		$string = preg_replace( '/December/i', "December", $string );

		return strtotime( $string );
	}
}