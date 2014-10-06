<?php

// This is a PLUGIN TEMPLATE for Textpattern CMS.

// Copy this file to a new name like abc_myplugin.php.  Edit the code, then
// run this file at the command line to produce a plugin for distribution:
// $ php abc_myplugin.php > abc_myplugin-0.1.txt

// Plugin name is optional.  If unset, it will be extracted from the current
// file name. Plugin names should start with a three letter prefix which is
// unique and reserved for each plugin author ("abc" is just an example).
// Uncomment and edit this line to override:
$plugin['name'] = 'smd_xml';

// Allow raw HTML help, as opposed to Textile.
// 0 = Plugin help is in Textile format, no raw HTML allowed (default).
// 1 = Plugin help is in raw HTML.  Not recommended.
# $plugin['allow_html_help'] = 1;

$plugin['version'] = '0.41';
$plugin['author'] = 'Stef Dawson';
$plugin['author_uri'] = 'http://stefdawson.com/';
$plugin['description'] = 'Extract any XML/feed info and reformat it';

// Plugin load order:
// The default value of 5 would fit most plugins, while for instance comment
// spam evaluators or URL redirectors would probably want to run earlier
// (1...4) to prepare the environment for everything else that follows.
// Values 6...9 should be considered for plugins which would work late.
// This order is user-overrideable.
$plugin['order'] = '5';

// Plugin 'type' defines where the plugin is loaded
// 0 = public              : only on the public side of the website (default)
// 1 = public+admin        : on both the public and admin side
// 2 = library             : only when include_plugin() or require_plugin() is called
// 3 = admin               : only on the admin side (no AJAX)
// 4 = admin+ajax          : only on the admin side (AJAX supported)
// 5 = public+admin+ajax   : on both the public and admin side (AJAX supported)
$plugin['type'] = '0';

// Plugin "flags" signal the presence of optional capabilities to the core plugin loader.
// Use an appropriately OR-ed combination of these flags.
// The four high-order bits 0xf000 are available for this plugin's private use
if (!defined('PLUGIN_HAS_PREFS')) define('PLUGIN_HAS_PREFS', 0x0001); // This plugin wants to receive "plugin_prefs.{$plugin['name']}" events
if (!defined('PLUGIN_LIFECYCLE_NOTIFY')) define('PLUGIN_LIFECYCLE_NOTIFY', 0x0002); // This plugin wants to receive "plugin_lifecycle.{$plugin['name']}" events

$plugin['flags'] = '0';

// Plugin 'textpack' is optional. It provides i18n strings to be used in conjunction with gTxt().
// Syntax:
// ## arbitrary comment
// #@event
// #@language ISO-LANGUAGE-CODE
// abc_string_name => Localized String

/** Uncomment me, if you need a textpack
$plugin['textpack'] = <<< EOT
#@admin
#@language en-gb
abc_sample_string => Sample String
abc_one_more => One more
#@language de-de
abc_sample_string => Beispieltext
abc_one_more => Noch einer
EOT;
**/
// End of textpack

if (!defined('txpinterface'))
        @include_once('zem_tpl.php');

# --- BEGIN PLUGIN CODE ---
/**
 * smd_xml: a Textpattern CMS plugin for reading and parsing XML data
 *
 * Features:
 *  -> Read XML stream from URL, string or (experimental) SOAP client. XSL transform available
 *  -> Specify field hierarchy to extract specific parts of the data stream
 *  -> Optionally format data items prior to output
 *  -> Cacheing supported to ease server load
 *  -> Results can be paged
 *
 * @author Stef Dawson
 * @link   http://stefdawson.com/
 */

// TODO:
// Add headers attribute, allowing User-Agent etc to be added. Will have to be a fixed list, but if it's made into an array and iterated, then it leaves the door open for expansion.
//      --> JSON support?
//      -->   Some way of grouping items, maybe via concat. e.g. instead of assigning output directly to $out[], add the group to a pseudo-tag in $xmldata,
//      -->     e.g. concat="2|Measure=>My_Measurements" will make {My_Measurements} available in the container which will contain the concatenated output of all (possibly processed with ontag) Measure tags
//     Allow fields="NodeWrapper->*" to specify that you want all sub-nodes to be included in the replacement stream
//      -->  Perhaps some mechanism to specify the number of levels to go down as well, e.g.:
//      -->    NodeWrapper->*    =  all sub-nodes
//      -->    NodeWrapper->*1   =  1st-child sub-nodes
//      -->    NodeWrapper->*2   =  2nd-child sub-nodes
//      -->    NodeWrapper->*1+2 =  1st and 2nd-child sub-nodes
//      -->    NodeWrapper->*1-4 =  1st thru 4th-child sub-nodes
function smd_xml($atts, $thing=NULL) {
	global $pretext, $thispage, $smd_xml_pginfo;

	extract(lAtts(array(
		'data'             => '',
		'datawrap'         => '',
		'record'           => '',
		'fields'           => '',
		'skip'             => '',
		'match'            => '',
		'timeout'          => 10,
		'transform'        => '',
		'kill_spaces'      => 1,
		'ontagstart'       => '',
		'ontagend'         => '',
		'load_atts'        => 'start', // (tag) start or end
		'uppercase'        => '0',
		'convert'          => '', // search|replace, search|replace, ...
		'target_enc'       => 'UTF-8',
		'defaults'         => '',
		'set_empty'        => '0',
		'format'           => '',
		'form'             => '',
		'pageform'         => '',
		'pagevar'          => 'pg',
		'pagepos'          => 'below',
		'delim'            => ',',
		'param_delim'      => '|',
		'tag_delim'        => '|',
		'concat'           => '1',
		'concat_delim'     => ' ',
		'transport'        => '',
		'transport_opts'   => '',
		'transport_config' => '',
		'cache_time'       => '3600', // in seconds
		'hashsize'         => '6:5',
		'line_length'      => '8192',
		'var_prefix'       => ', smd_xml_',
		'limit'            => 0,
		'offset'           => 0,
		'wraptag'          => '',
		'break'            => '',
		'class'            => '',
		'debug'            => '0',
	), $atts));

	// This constant is only available in PHP 5.4+ so fake it for earlier versions
	// and take the hit on them not knowing what the flag means
	if (!defined('ENT_XML1')) {
		define('ENT_XML1', 16);
	}

	$src = '';
	$thing = (empty($form)) ? $thing : fetch_form($form);
	$soap_wrapped = false;

	if (empty($data)) {
		trigger_error("smd_xml requires a data source", E_USER_ERROR);
		return;
	}
	if (empty($record)) {
		trigger_error("smd_xml requires a record name within your data stream", E_USER_ERROR);
		return;
	}

	// Work out where the paging info is to appear
	$pagebit = $rowinfo = array();
	if ($pageform) {
		$pagePosAllowed = array("below", "above");
		$pageform = fetch_form($pageform);
		$pagepos = str_replace('smd_', '', $pagepos); // For convenience
		$pagepos = do_list($pagepos, $delim);
		foreach ($pagepos as $pageitem) {
			$pagebit[] = (in_array($pageitem, $pagePosAllowed)) ? $pageitem : $pagePosAllowed[0];
		}
	}

	$target_enc = (in_array($target_enc, array('ISO-8859-1', 'US-ASCII', 'UTF-8'))) ? $target_enc : 'UTF-8';

	// Extract the prefixes
	$prefixes = do_list($var_prefix);
	$tag_prefix = $prefixes[0];
	$page_prefix = isset($prefixes[1]) ? $prefixes[1] : $tag_prefix;

	// Make a unique hash value for this instance so the XML document can be cached in txp_prefs
	$uniq = '';
	$md5 = md5($data.$record.$fields);
	list($hashLen, $hashSkip) = explode(':', $hashsize);
	for ($idx = 0, $cnt = 0; $cnt < $hashLen; $cnt++, $idx = (($idx+$hashSkip) % strlen($md5))) {
		$uniq .= $md5[$idx];
	}

	$var_lastmod = 'smd_xml_lmod_'.$uniq;
	$var_data = 'smd_xml_data_'.$uniq;
	$lastmod = get_pref($var_lastmod, 0);
	$read_cache = (($cache_time > 0) && ((time() - $lastmod) < $cache_time)) ? true : false;
	$crush = function_exists('gzcompress') && function_exists('gzuncompress');
	$pagevar = ($pagevar == 'SMD_XML_UNIQUE_ID') ? $uniq : $pagevar;

	// Cached document is gzipped and then (yuk!) base64'd if zlib is compiled in.
	// Would prefer to store binary data directly but trying to insert it into a txp_prefs
	// text field always gives problems on insertion and/or retrieval
	if ($read_cache) {
		if ($debug > 1) {
			trace_add ('[smd_xml reading cache '.$var_data.']');
		}
		$src = $crush ? gzuncompress(base64_decode(get_pref($var_data))) : get_pref($var_data);
	} else {
		if ((strpos($data, 'http:') === 0) || (strpos($data, 'https:') === 0) || (strpos($data, 'ftp:') === 0)) {
			// The data is to be fetched from a URL.
			// Has the transport mechanism been specified? If not, choose one
			if (!$transport) {
				if ( function_exists('curl_version') ) {
					$transport = 'curl';
				} else if ( function_exists('fsockopen') ) {
					$transport = 'fsock';
				} else {
					$transport = '';
				}
			}

			switch ($transport) {
				case 'curl':
					$src = smd_xml_curl($data, $timeout);
				break;
				case 'fsock':
					$url = parse_url($data);
					switch ($url['scheme']) {
						case 'https':
							$url['scheme'] = 'ssl://';
							$url['port'] = 443;
						break;
						case 'ftp':
							$url['scheme'] = '';
							$url['port'] = 21;
						break;
						case 'http':
						default:
							$url['scheme'] = '';
							$url['port'] = 80;
					}
					$fp = fsockopen ($url['scheme'] . $url['host'], $url['port'], $errno, $errstr, $timeout);

					$qry = 'GET '.$url['path'] . ((isset($url['query'])) ? '?'.$url['query'] : '');
					$qry .= " HTTP/1.0\r\n";
					$qry .= "Host: ".$url['host']."\r\n";
					$qry .= "User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-GB; rv:1.9.1.6) Gecko/20091201 Firefox/3.5.6\r\n\r\n"; // *shrug*

					fputs($fp, $qry);
					stream_set_timeout($fp, $timeout);
					$info = stream_get_meta_data($fp);

					$hdrs = true;
					while ((!feof($fp)) && (!$info['timed_out'])) {
						$line = fgets($fp, $line_length);
						$line = preg_replace("[\r\n]", "", $line);
						if ($hdrs == false) {
							$src .= $line."\n";
						}
						if (strlen($line) == 0) $hdrs = false;
					}
					if ($info['timed_out']) {
						$src = '';
					}
					fclose($fp);
				break;
				case 'soap':
					ini_set('soap.wsdl_cache_enabled', '0');
					if (class_exists('SoapClient')) {
						$url = $data;
						$url .= (stripos($url, '?WSDL') === false) ? '?WSDL' : '';

						$client = '';

						try {
							$client = new SoapClient($url);
						} catch (SoapFault $E) {
							echo $E->faultstring;
							$src = '';
						}

						if ($client) {
							// Use explode() because do_list() removes spaces
							$transport_config = explode($delim, $transport_config);
							$tcfg = array();
							foreach ($transport_config as $transopt) {
								$topts = explode($param_delim, $transopt);
								if (count($topts) == 2) {
									$tcfg[$topts[0]] = $topts[1];
								}
							}

							$tcfg['soap_delim'] = isset($tcfg['soap_delim']) ? $tcfg['soap_delim'] : $param_delim;
							$tcfg['soap_type_input'] = isset($tcfg['soap_type_input']) ? $tcfg['soap_type_input'] : 'nvpairs';
							$tcfg['soap_type_output'] = isset($tcfg['soap_type_output']) ? $tcfg['soap_type_output'] : '';
							$tcfg['soap_numeric_wrap'] = isset($tcfg['soap_numeric_wrap']) ? $tcfg['soap_numeric_wrap'] : $record;

							$transItems = do_list($transport_opts, $delim);
							$cliFn = $transItems[0];
							$params = array();

							switch ($tcfg['soap_type_input']) {
								case 'nvpairs':
									$param = do_list($transItems[1], $param_delim);
									$num = (count($param) / 2);
									for ($idx = 0; $idx < $num; $idx++) {
										$key = array_shift($param);
										$val = array_shift($param);
										$params[$key] = $val;
									}
								break;
								case 'xml':
									$doc = new DOMDocument();
									$doc->preserveWhiteSpace = false;
									$doc->loadXML($transItems[1]);
									$params = smd_xml_to_array($doc->documentElement);
									if (isset($tcfg['soap_wrap'])) {
										$params[$tcfg['soap_wrap']] = $params;
									}
								break;
							}
							$resParts = do_list($transItems[2], $param_delim);
							$resFn = array_shift($resParts);

							$result = '';
							try {
							   $result = $client->$cliFn($params);
							} catch (SoapFault $E) {
							   echo $E->faultstring;
							}

							if ($result) {
								if ($resFn) {
									try {
										$src = $result->$resFn;
										if ($resParts) {
											$srcbobs = array();
											foreach ($resParts as $resIdx) {
												$srcbobs[] = $src->$resIdx;
											}

											$src = join($tcfg['soap_delim'], $srcbobs);
										}
										switch ($tcfg['soap_type_output']) {
											case 'xml':
												// Oh lordy me -- just to convert stdClass:: format to associative array
												$xmlbld = new smd_xml_build_data(json_decode(json_encode($src), true), $datawrap, $tcfg['soap_numeric_wrap']);
												$soap_wrapped = true;
												$src = $xmlbld->getData();
											break;
										}
									} catch (SoapFault $E) {
									   echo $E->faultstring;
									}
								} else {
									$src = $result;
								}
							} else {
								$src = '';
							}
						}
					} else {
						$src = '';
					}
				break;
				default:
					$src = '';
			}
		} else  {
			// Assume data is presented in raw XML
			$src = $data;
		}
	}

	// Remove inter-tag whitespace: highly recommended, but makes the feed less easy to debug
	// so you may elect to turn it off while testing
	if ($kill_spaces) {
		$src = preg_replace("/>"."[[:space:]]+"."</i", "><", $src);
	}

	// Perform transformations on the fetched source
	if ($transform) {
		$xforms = do_list($transform, $delim);
		foreach ($xforms as $xform) {
			$tops = do_list($xform, $param_delim);
			$ttyp = array_shift($tops);
			switch ($ttyp) {
				case 'xsl':
					$xsl = smd_xml_curl($tops[0], $timeout);
					$src = smd_xml_xsl_transform($src, $xsl);
				break;
				case 'replace':
					$src = preg_replace($tops[0], (isset($tops[1]) ? $tops[1] : ''), $src);
				break;
			}
		}
	}

	// Store the current document in the cache and datestamp it
	if ($cache_time > 0 && !$read_cache) {
		$srcinfo = $crush ? base64_encode(gzcompress($src)) : doSlash($src);
		set_pref($var_lastmod, time(), 'smd_xml', PREF_HIDDEN, 'text_input');
		set_pref($var_data, $srcinfo, 'smd_xml', PREF_HIDDEN, 'text_input');
	}

	// Make up a replacement array for decoded entities...
	$conversions = array();
	$convert = do_list($convert, $delim);
	foreach ($convert as $pair) {
		if (empty($pair)) continue;

		$pair = do_list($pair, $param_delim);
		$conversions[$pair[0]] = $pair[1];
	}

	if ($src && ($debug > 1)) {
		trace_add ('[smd_xml conversions: ' . print_r($conversions, true) . ']');
	}

	// ... and replace them
	$src = strtr($src, $conversions);

	// Wrap if necessary
	if ($datawrap && !$soap_wrapped) {
		$src = "<$datawrap>$src</$datawrap>";
	}

	if ($debug > 2) {
		trace_add ('[smd_xml filtered source: ' . $src . ']');
	}

	// Set up any ontag processing
	$ontagstart = do_list($ontagstart);
	$ontagend = do_list($ontagend);
	$watchStart = $watchEnd = $watchForm = array();
	foreach ($ontagstart as $ontag) {
		if ($ontag == '') continue;
		$parts = explode($param_delim, $ontag);
		$frm = array_shift($parts);
		$watchStart[$frm] = $parts;
		$watchForm[$frm] = fetch_form($frm);
	}
	foreach ($ontagend as $ontag) {
		if ($ontag == '') continue;
		$parts = explode($param_delim, $ontag);
		$frm = array_shift($parts);
		$watchEnd[$frm] = $parts;
		$watchForm[$frm] = fetch_form($frm);
	}

	if ($src && ($debug > 1)) {
		trace_add ('[smd_xml start watchers: ' . print_r($watchStart, true) . ']');
		trace_add ('[smd_xml end watchers: ' . print_r($watchEnd, true) . ']');
		trace_add ('[smd_xml watch forms: ' . print_r($watchForm, true) . ']');
	}

	// Set up any defaults
	$defaults = do_list($defaults, $delim);
	$dflts = array();
	foreach ($defaults as $dflt) {
		if ($dflt == '') continue;
		$parts = explode($param_delim, $dflt);
		$dflts[$parts[0]] = $parts[1];
	}
	$defaults = $dflts;

	// Set up any formatting
	$format = do_list($format, $delim);
	$formats = array();
	foreach ($format as $frmdef) {
		if ($frmdef == '') continue;
		$parts = explode($param_delim, $frmdef);
		$formats['type'][$parts[0]] = $parts[1];
		for($idx = 0; $idx < count($parts)-2; $idx++) {
			$formats['data'][$parts[0]][] = $parts[$idx+2];
		}
	}

	// Set up any matches
	$match = do_list($match, $delim);
	$matches = array();
	foreach ($match as $item) {
		if ($item == '') continue;
		$parts = explode($param_delim, $item);
		$matches[$parts[0]] = $parts[1];
	}
	$match = $matches;

	if ($src && ($debug > 1)) {
		if ($defaults) {
			trace_add ('[smd_xml defaults: ' . print_r($defaults, true) . ']');
		}
		if ($formats) {
			trace_add ('[smd_xml formats: ' . print_r($formats, true) . ']');
		}
		if ($match) {
			trace_add ('[smd_xml matches: ' . print_r($match, true) . ']');
		}
	}

	if (!empty($src)) {
		// Paging information
		$rowinfo['numrecs'] = substr_count($src, '<'.$record);
		$rowinfo['page_rowcnt'] = 0;
		$rowinfo['limit'] = ($limit < $rowinfo['numrecs']) ? $limit : 0;
		if ($offset >= 0) {
			if ($offset < $rowinfo['numrecs']) {
				$rowinfo['offset'] = $offset;
			} else {
				$rowinfo['offset'] = $rowinfo['numrecs'];
				$rowinfo['limit'] = 0;
			}
		} else {
			$negoff = $rowinfo['numrecs'] + $offset;
			if ($negoff > 0) {
				$rowinfo['offset'] = $negoff;
			} else {
				$rowinfo['offset'] = 0;
				$rowinfo['limit'] = $rowinfo['numrecs'];
			}
		}

		// Re-assign the atts in case they've been changed by reaching the bounds of the document
		$offset = $rowinfo['offset'];
		$limit = $rowinfo['limit'];

		if ($limit > 0) {
			$keepsafe = $thispage;
			$rowinfo['total'] = $rowinfo['numrecs'] - $offset;
			$rowinfo['numPages'] = ceil($rowinfo['total'] / $limit);
			$rowinfo['pg'] = (!gps($pagevar)) ? 1 : gps($pagevar);
			$rowinfo['pgoffset'] = $offset + (($rowinfo['pg'] - 1) * $limit);
			$rowinfo['prevpg'] = (($rowinfo['pg']-1) > 0) ? $rowinfo['pg']-1 : '';
			$rowinfo['nextpg'] = (($rowinfo['pg']+1) <= $rowinfo['numPages']) ? $rowinfo['pg']+1 : '';
			$rowinfo['pagerows'] = ($rowinfo['pg'] == $rowinfo['numPages']) ? $rowinfo['total']-($limit * ($rowinfo['numPages']-1)) : $limit;
			$rowinfo['unique_id'] = $uniq;

			// send paging info to txp:newer and txp:older
			$pageout['pg'] = $rowinfo['pg'];
			$pageout['numPages'] = $rowinfo['numPages'];
			$pageout['s'] = $pretext['s'];
			$pageout['c'] = $pretext['c'];
			$pageout['grand_total'] = $rowinfo['numrecs'];
			$pageout['total'] = $rowinfo['total'];
			$thispage = $pageout;
		} else {
			$rowinfo['pgoffset'] = $offset;
		}

		$rowinfo['running_rowcnt'] = $rowinfo['pgoffset']-$offset;
		$rowinfo['first_rec'] = $rowinfo['running_rowcnt'] + 1;
		$rowinfo['last_rec'] = ($limit > 0) ? $rowinfo['first_rec'] + $rowinfo['pagerows'] - 1 : $rowinfo['numrecs'];
		if ($limit > 0) {
			$rowinfo['prev_rows'] = (($rowinfo['prevpg']) ? $limit : 0);
			$rowinfo['next_rows'] = (($rowinfo['nextpg']) ? (($rowinfo['last_rec']+$limit+1) > $rowinfo['total'] ? $rowinfo['total']-$rowinfo['last_rec'] : $limit) : 0);
		}

		if ($debug > 0) {
			trace_add ('[smd_xml paging info: ' . print_r($rowinfo, true) . ']');
		}

		// Do the dirty XML deed
		$ref = new smd_xml_parser(array(
			'src'          => $src,
			'delim'        => $delim,
			'param_delim'  => $param_delim,
			'tag_delim'    => $tag_delim,
			'concat'       => $concat,
			'concat_delim' => $concat_delim,
			'fields'       => $fields,
			'skip'         => $skip,
			'match'        => $match,
			'record'       => $record,
			'casefold'     => $uppercase,
			'target_enc'   => $target_enc,
			'tag_prefix'   => $tag_prefix,
			'page_prefix'  => $page_prefix,
			'defaults'     => $defaults,
			'load_atts'    => $load_atts,
			'watchStart'   => $watchStart,
			'watchEnd'     => $watchEnd,
			'watchForm'    => $watchForm,
			'set_empty'    => $set_empty,
			'formats'      => $formats,
			'thing'        => $thing,
			'rinfo'        => $rowinfo,
			'debug'        => $debug,
		));

		// Grab the parsed results
		$result = $ref->getResults();

		// Create the page form
		$pageblock = '';
		$finalout = $repagements = array();

		if ($rowinfo['limit'] > 0) {
			$repagements['{'.$page_prefix.'totalrecs}'] = $rowinfo['total'];
			$repagements['{'.$page_prefix.'pagerecs}']  = $rowinfo['pagerows'];
			$repagements['{'.$page_prefix.'pages}']     = $rowinfo['numPages'];
			$repagements['{'.$page_prefix.'prevpage}']  = $rowinfo['prevpg'];
			$repagements['{'.$page_prefix.'thispage}']  = $rowinfo['pg'];
			$repagements['{'.$page_prefix.'nextpage}']  = $rowinfo['nextpg'];
			$repagements['{'.$page_prefix.'rec_start}'] = $rowinfo['first_rec'];
			$repagements['{'.$page_prefix.'rec_end}']   = $rowinfo['last_rec'];
			$repagements['{'.$page_prefix.'recs_prev}'] = $rowinfo['prev_rows'];
			$repagements['{'.$page_prefix.'recs_next}'] = $rowinfo['next_rows'];
			$repagements['{'.$page_prefix.'unique_id}'] = $rowinfo['unique_id'];
			$smd_xml_pginfo = $repagements;
			$pageblock = parse(strtr($pageform, $repagements));
		}

		// Make up the final output
		if (in_array("above", $pagebit)) {
			$finalout[] = $pageblock;
		}
		$finalout[] = doWrap($result, $wraptag, $break, $class);
		if (in_array("below", $pagebit)) {
			$finalout[] = $pageblock;
		}

		// Restore the paging outside the plugin container
		if ($limit > 0) {
			$thispage = $keepsafe;
		}

		return join('', $finalout);
	} else {
		return '';
	}
}

// Convenience tags to check if there's a prev/next page defined. Could also use smd_if
function smd_xml_if_prev($atts, $thing) {
	global $smd_xml_pginfo;

	$res = $smd_xml_pginfo && $smd_xml_pginfo['{smd_xml_prevpage}'] != '';
	return parse(EvalElse(strtr($thing, $smd_xml_pginfo), $res));
}
function smd_xml_if_next($atts, $thing) {
	global $smd_xml_pginfo;

	$res = $smd_xml_pginfo && $smd_xml_pginfo['{smd_xml_nextpage}'] != '';
	return parse(EvalElse(strtr($thing, $smd_xml_pginfo), $res));
}

/*****************
 FUNCTION LIBRARY
*****************/
// Retrieve a resource via curl; return false otherwise
function smd_xml_curl($data, $timeout=10) {
	$ret = false;

	if (function_exists('curl_version')) {
		$c = curl_init();
		curl_setopt($c, CURLOPT_URL, $data);
		curl_setopt($c, CURLOPT_REFERER, hu);
		curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($c, CURLOPT_VERBOSE, false);
		curl_setopt($c, CURLOPT_TIMEOUT, $timeout);
		$ret = curl_exec($c);
	}

	return $ret;
}

// Transform an XML document using the given XSL stylesheet
function smd_xml_xsl_transform($xml, $xsl) {
	$ret = $xml;

	if (class_exists('XSLTProcessor')) {
		$xslt = new XSLTProcessor();
		$xslt->importStylesheet(new SimpleXMLElement($xsl));
		$ret = $xslt->transformToXml(new SimpleXMLElement($xml));
	}

	return $ret;
}

// Convert XML document to associative array
// (from http://stackoverflow.com/questions/99350/php-associative-arrays-to-and-from-xml)
function smd_xml_to_array($curr_node) {
	$val_array = array();
	$typ_array = array();

	foreach($curr_node->childNodes as $node) {
		if ($node->nodeType == XML_ELEMENT_NODE) {
			$val = smd_xml_to_array($node);
			if (array_key_exists($node->tagName, $val_array)) {
				if (!is_array($val_array[$node->tagName]) || $type_array[$node->tagName] == 'hash') {
					$existing_val = $val_array[$node->tagName];
					unset($val_array[$node->tagName]);
					$val_array[$node->tagName][0] = $existing_val;
					$type_array[$node->tagName] = 'array';
				}
				$val_array[$node->tagName][] = $val;
			} else {
				$val_array[$node->tagName] = $val;
				if (is_array($val)) {
					$type_array[$node->tagName] = 'hash';
				}
			} // end if array key exists
		} // end if element node
	} // end for each

	if (count($val_array) == 0) {
		return $curr_node->nodeValue;
	} else {
		return $val_array;
	}
}

// Build an XML data set from associative array
class smd_xml_build_data {
	private $xml, $last_idx, $recWrap;

	function smd_xml_build_data ($data, $startElement, $recWrap, $xml_version = '1.0', $xml_encoding = 'UTF-8') {
		$startElement = ($startElement) ? $startElement : 'fx_request';
		if (!is_array($data)) {
			$err = 'Invalid variable type supplied, expected array not found on line '.__LINE__." in Class: ".__CLASS__." Method: ".__METHOD__;
			trigger_error($err);
			return false;
		}
		$this->xml = new XmlWriter();
		$this->xml->openMemory();
		$this->xml->startDocument($xml_version, $xml_encoding);
		$this->xml->startElement($startElement);

		$this->last_idx = 0;
		$this->recWrap = $recWrap;
		$this->write($this->xml, $data, $startElement);

		$this->xml->endElement();
	}

	// Standard getter
	function getData() {
		return $this->xml->outputMemory(true);
	}

	// Recurse array elements and build XML tag tree
	function write(XMLWriter $xml, $data, $parent) {
		foreach ($data as $key => $value) {
			// Nodes that aren't valid attributes get given an array index
			if (!preg_match('/[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*/', $key)) {
				$key = ($this->recWrap) ? $this->recWrap : ($parent . '_' . (is_numeric($key) ? $key : $last_idx));
			}
			if (is_array($value)) {
				$xml->startElement($key);
				$this->write($xml, $value, $key);
				$xml->endElement();
				continue;
			}
			$xml->writeElement($key, $value);
			$this->last_idx++;
		}
	}
}

// Dirty XML work done here
class smd_xml_parser {
	private $data, $rec;
	private $fields, $subfields, $treefields;
	private $skip, $match, $defaults, $set_empty;
	private $casefold, $outenc;
	private $load_atts, $watchStart, $watchEnd, $watchForm;
	private $formats, $tag_prefix, $page_prefix;

	private $intag, $indata;
	private $skiptag, $xmltag, $xmlatts, $xmldata;
	private $thing, $out;
	private $delim, $concat, $tdelim, $cdelim;
	private $rowinfo, $show_record;
	private $debug;

	/**
	* constructor
	*/
	function __construct($atts) {
		$this->data        = $atts['src'];
		$this->delim       = $atts['delim'];
		$this->tdelim      = $atts['tag_delim'];
		$this->concat      = $atts['concat'];
		$this->cdelim      = $atts['concat_delim'];
		$this->fields      = do_list($atts['fields'], $this->delim);
		$this->skip        = do_list($atts['skip'], $this->delim);
		$this->match       = $atts['match'];
		$this->rec         = $atts['record'];
		$this->casefold    = $atts['casefold'];
		$this->outenc      = $atts['target_enc'];
		$this->tag_prefix  = $atts['tag_prefix'];
		$this->page_prefix = $atts['page_prefix'];
		$this->defaults    = $atts['defaults'];
		$this->load_atts   = $atts['load_atts'];
		$this->watchStart  = $atts['watchStart'];
		$this->watchEnd    = $atts['watchEnd'];
		$this->watchForm   = $atts['watchForm'];
		$this->set_empty   = $atts['set_empty'];
		$this->formats     = $atts['formats'];
		$this->thing       = $atts['thing'];
		$this->rowinfo     = $atts['rinfo'];
		$this->debug       = $atts['debug'];

		$this->subfields  = array();
		$this->treefields = array();
		$this->tagtree    = array();
		$this->xmldata    = array();
		$this->out        = array();
		$this->intag      = false;
		$this->exists     = false;
		$this->skiptag    = '';
		$this->xmltag     = '';
		$this->xmltatts   = '';

		// Copy any tree- and sub-fields out of the list
		foreach ($this->fields as $key => $fld) {
			$sf = do_list($fld, $this->tdelim);
			$tf = do_list($sf[0], '->');
			$numSFs = count($sf);
			$numTFs = count($tf);

			// First subfield needs tree portion removing, and
			// last treefield needs removing ('cos it's the first subfield)
			$sf[0] = $tf[$numTFs-1];
			unset($tf[$numTFs-1]);
			$numTFs--;

			// Build the tree view.
			// The tree holds the path from the sub-node back to the top, so when
			// a leaf is encountered its path can be looked up as $treefields[$leaf]
			// and be path verified to see if it matches a wanted leaf
			foreach ($sf as $sub) {
				$this->treefields[$sub] = $tf;
			}

			// The subfields are built as a pair of arrays -- one flat, one indexed by parent field -- for searching later
			$this->byfields[$sf[0]] = array_slice($sf, 1);
			$this->subfields = array_merge($this->subfields, $sf);

			// Make sure the field only holds the zeroth entry
			$this->fields[$key] = ($tf) ? $tf[0] : $sf[0];

			// and make sure the root node of any tree is not added
			// as checkable to the tree itself
			$this->treefields[$this->fields[$key]] = array();
		}
		if ($this->debug > 1) {
			trace_add ('[smd_xml fields: ' . print_r($this->fields, true) . ']');
			trace_add ('[smd_xml subfields: ' . print_r($this->subfields, true) . ']');
			trace_add ('[smd_xml indexed subfields: ' . print_r($this->byfields, true) . ']');
			trace_add ('[smd_xml tree fields: ' . print_r($this->treefields, true) . ']');
		}
		$this->parse();
	}

	public function getResults() {
		if ($this->out) {
			return $this->out;
		} else {
			return '';
		}
	}

	private function parse() {
		$xmlparser = xml_parser_create();
		xml_set_object($xmlparser, $this);
		xml_parser_set_option($xmlparser, XML_OPTION_CASE_FOLDING, $this->casefold);
		xml_parser_set_option($xmlparser, XML_OPTION_TARGET_ENCODING, $this->outenc);
		xml_set_default_handler($xmlparser, "smd_xml_default");
		xml_set_element_handler($xmlparser, "smd_xml_start_tag", "smd_xml_end_tag");
		xml_set_character_data_handler($xmlparser, "smd_xml_tag_contents");
		xml_parse($xmlparser, $this->data);
		xml_parser_free($xmlparser);
	}

	// Do nothing with default (non-XML) data. Just report it in debug mode
	// TODO: allow some callback / Form to handle this type of data
	private function smd_xml_default($parser, $data) {
		if ($this->debug > 1) {
			trace_add ('[smd_xml default data: ' . print_r($data, true) . ']');
		}
	}

	// Start of XML tag
	private function smd_xml_start_tag($parser, $name, $attribs) {
		array_push($this->tagtree, $name);

		$pgval = $this->rowinfo['pgoffset'] - 1;
		$lim = $this->rowinfo['limit'] > 0;

		// Is this record to be processed? i.e. is it within this page's limit/offset?
		$this->show_record = $lim ? (($this->rowinfo['page_rowcnt'] > $pgval) && ($this->rowinfo['page_rowcnt'] <= $pgval + $this->rowinfo['pagerows'])) : $this->rowinfo['page_rowcnt'] > $pgval;

		// Check the tree if necessary
		$tree_ok = true;
		if (array_key_exists($name, $this->treefields) && !empty($this->treefields[$name])) {
			$treeSize = count($this->treefields[$name]);
			$treeOffset = '-' .$treeSize - 1;
			// If there's no difference, the tree is ok
			$tree_ok = ( array_diff ($this->treefields[$name], array_slice($this->tagtree, $treeOffset, $treeSize) ) ) ? false : true;
			if ($this->debug > 2) {
				trace_add ('[smd_xml tree check on field: ' . $name . ']');
				trace_add ('[smd_xml tree compare: ' . print_r($this->treefields[$name], true) . ']');
				trace_add ('[smd_xml tree with: ' . print_r($this->tagtree, true) . ']');
				trace_add ('[smd_xml tree result: ' . ($tree_ok ? 'YES' : 'NO') . ']');
			}
		}

		// Start of a wanted record: flag this situation and grab any attributes
		if ( ($name == $this->rec) && $this->show_record ) {
			$this->intag = true;
			$this->xmlatts[$name] = $attribs;
			if ($this->load_atts == 'start') {
				$this->smd_xml_store_attribs($name);
			}
		}

		// We're inside a wanted record
		if ($this->intag && $tree_ok) {
			if (in_array($name, $this->skip)) {
				$this->xmltag = '';
				$this->xmlatts[$name] = array();
				$this->skiptag = $name;
			} else {
				$this->xmltag = $name;
				$this->xmlatts[$name] = $attribs;
				if ( ($this->load_atts == 'start') && ($name != $this->rec) ) {
					$this->smd_xml_store_attribs($name);
				}

				// Process any ontagstart
				foreach ($this->watchStart as $frm => $watchlist) {
					if (in_array($name, $watchlist)) {
						$op = trim(parse(strtr($this->watchForm[$frm], $this->xmldata)));
						if ($op != '') {
							$this->out[] = $op;
						}
					}
				}
				if ($this->concat && isset($this->xmldata['{'.$this->tag_prefix.$this->xmltag.'}'])) {
					$this->exists = true;
				} else {
					$this->exists = false;
				}
			}
		}
		$this->indata = false;
	}

	// End of XML tag
	private function smd_xml_end_tag($parser, $name) {
		// End of a regular/attribute-only/container/self-closing tag
		if ( ($name != $this->rec) && ($name != $this->skiptag) && $this->intag && in_array($name, array_merge($this->fields, $this->subfields)) ) {
			$this->xmltag = $name;
			if ($this->load_atts == 'end') {
				$this->smd_xml_store_attribs($name);
			}

			// Process any ontagend
			foreach ($this->watchEnd as $frm => $watchlist) {
				if (in_array($name, $watchlist)) {
					$op = trim(parse(strtr($this->watchForm[$frm], $this->xmldata)));
					if ($op != '') {
						$this->out[] = $op;
					}
				}
			}
		}

		// End of the record
		if ($name == $this->rec && $name != $this->skiptag) {
			$this->intag = false;
			if ($this->load_atts == 'end') {
				$this->smd_xml_store_attribs($name);
			}

			$matched = true;
			if (array_key_exists($this->xmltag, $this->match)) {
				$matched = preg_match($this->match[$this->xmltag], $this->xmldata['{'.$this->tag_prefix.$this->xmltag.'}']);
			}
			if ($matched) {
				$lim = ($this->rowinfo['limit'] > 0) ? true : false;

				// Append row counter information
				$this->xmldata['{'.$this->page_prefix.'totalrecs}'] = $lim ? $this->rowinfo['total'] : $this->rowinfo['numrecs'] - $this->rowinfo['pgoffset'];
				$this->xmldata['{'.$this->page_prefix.'pagerecs}']  = $lim ? $this->rowinfo['pagerows'] : $this->xmldata['{'.$this->page_prefix.'totalrecs}'];
				$this->xmldata['{'.$this->page_prefix.'pages}']     = $lim ? $this->rowinfo['numPages'] : 1;
				$this->xmldata['{'.$this->page_prefix.'thispage}']  = $lim ? $this->rowinfo['pg'] : 1;
				$this->xmldata['{'.$this->page_prefix.'thisindex}'] = $this->rowinfo['page_rowcnt'] - $this->rowinfo['offset'];
				$this->xmldata['{'.$this->page_prefix.'thisrec}']   = $this->rowinfo['page_rowcnt'] - $this->rowinfo['offset'] + 1;
				$this->xmldata['{'.$this->page_prefix.'runindex}']  = $this->rowinfo['running_rowcnt'];
				$this->xmldata['{'.$this->page_prefix.'runrec}']    = $this->rowinfo['running_rowcnt'] + 1;

				$sfields = array_unique(array_merge($this->fields, $this->subfields));

				// Set any tag contents to a default value, if specified
				if ($this->defaults || $this->set_empty) {
					foreach ($sfields as $field) {
						if (!isset($this->xmldata['{'.$this->tag_prefix.$field.'}'])) {
							if (array_key_exists($field, $this->defaults)) {
								$this->xmldata['{'.$this->tag_prefix.$field.'}'] = $this->defaults[$field];
							} else if ($this->set_empty) {
								$this->xmldata['{'.$this->tag_prefix.$field.'}'] = '';
							}
						}
					}
				}

				// Reformat any fields, if specified
				if ($this->formats) {
					foreach ($sfields as $field) {
						if (isset($this->xmldata['{'.$this->tag_prefix.$field.'}']) && array_key_exists($field, $this->formats['type'])) {
							switch ($this->formats['type'][$field]) {
								case 'date':
									$nd = (is_numeric($this->xmldata['{'.$this->tag_prefix.$field.'}'])) ? $this->xmldata['{'.$this->tag_prefix.$field.'}'] : strtotime($this->xmldata['{'.$this->tag_prefix.$field.'}']);
									if ($nd !== false) {
										$this->xmldata['{'.$this->tag_prefix.$field.'}'] = strftime($this->formats['data'][$field][0], $nd);
									}
									break;
								case 'link':
									// From http://codesnippets.joyent.com/posts/show/2104
									$pat = "@\b(https?://)?(([0-9a-zA-Z_!~*'().&=+$%-]+:)?[0-9a-zA-Z_!~*'().&=+$%-]+\@)?(([0-9]{1,3}\.){3}[0-9]{1,3}|([0-9a-zA-Z_!~*'()-]+\.)*([0-9a-zA-Z][0-9a-zA-Z-]{0,61})?[0-9a-zA-Z]\.[a-zA-Z]{2,6})(:[0-9]{1,4})?((/[0-9a-zA-Z_!~*'().;?:\@&=+$,%#-]+)*/?)@";
									$this->xmldata['{'.$this->tag_prefix.$field.'}'] = preg_replace($pat, '<a href="$0">$0</a>', $this->xmldata['{'.$this->tag_prefix.$field.'}']);
									break;
								case 'escape':
									$flags = ENT_XML1;
									if (isset($this->formats['data'][$field][0])) {
										switch ($this->formats['data'][$field][0]) {
											case 'no_quotes':
												$flags |= ENT_NOQUOTES;
											break;
											case 'all_quotes':
												$flags |= ENT_QUOTES;
											break;
											case 'double_quotes':
											default:
												$flags |= ENT_COMPAT;
											break;
										}
									}
									$this->xmldata['{'.$this->tag_prefix.$field.'}'] = htmlspecialchars($this->xmldata['{'.$this->tag_prefix.$field.'}'], $flags);
									break;
								case 'fordb':
									$this->xmldata['{'.$this->tag_prefix.$field.'}'] = doSlash($this->xmldata['{'.$this->tag_prefix.$field.'}']);
									break;
								case 'sanitize':
									if ($this->formats['data'][$field][0] == "url") {
										$this->xmldata['{'.$this->tag_prefix.$field.'}'] = sanitizeForUrl($this->xmldata['{'.$this->tag_prefix.$field.'}']);
									} else if ($this->formats['data'][$field][0] == "file") {
										$this->xmldata['{'.$this->tag_prefix.$field.'}'] = sanitizeForFile($this->xmldata['{'.$this->tag_prefix.$field.'}']);
									} else if ($this->formats['data'][$field][0] == "url_title") {
										$this->xmldata['{'.$this->tag_prefix.$field.'}'] = stripSpace($this->xmldata['{'.$this->tag_prefix.$field.'}'], 1);
									}
									break;
								case 'case':
									for ($idx = 0; $idx < count($this->formats['data'][$field]); $idx++) {
										if ($this->formats['data'][$field][$idx] == "upper") {
											$this->xmldata['{'.$this->tag_prefix.$field.'}'] = strtoupper($this->xmldata['{'.$this->tag_prefix.$field.'}']);
										} else if ($this->formats['data'][$field][$idx] == "lower") {
											$this->xmldata['{'.$this->tag_prefix.$field.'}'] = strtolower($this->xmldata['{'.$this->tag_prefix.$field.'}']);
										} else if ($this->formats['data'][$field][$idx] == "ucfirst") {
											$this->xmldata['{'.$this->tag_prefix.$field.'}'] = ucfirst($this->xmldata['{'.$this->tag_prefix.$field.'}']);
										} else if ($this->formats['data'][$field][$idx] == "ucwords") {
											$this->xmldata['{'.$this->tag_prefix.$field.'}'] = ucwords($this->xmldata['{'.$this->tag_prefix.$field.'}']);
										}
									}
									break;
							}
						}
					}
				}

				if ($this->debug > 0 && $this->show_record) {
					trace_add ('[smd_xml replacements for record '.($this->rowinfo['running_rowcnt'] + 1).':' . print_r($this->xmldata, true) . ']');
				}
			}

			if ($this->show_record && $matched) {
				$this->out[] = parse(strtr($this->thing, $this->xmldata));
			}

			// Prepare for next record iteration
			$this->rowinfo['running_rowcnt'] = $this->rowinfo['running_rowcnt']+1;
			$this->rowinfo['page_rowcnt'] = $this->rowinfo['page_rowcnt']+1;
			$this->xmldata = array();
			$this->indata = false;
		}
		if ($name == $this->skiptag) {
			$this->skiptag = '';
		}
		array_pop($this->tagtree);
	}

	// Node data/text that is not an XML tag
	private function smd_xml_tag_contents($parser, $data) {
		if ($this->intag && !$this->skiptag) {
			if ($this->debug > 1) {
				trace_add ('[smd_xml tag:' . $this->xmltag . ']');
				trace_add ('[smd_xml tag data:' . print_r($data, true) . ']');
			}
			if (in_array($this->xmltag, array_merge($this->fields, $this->subfields))) {
				if ($this->indata) {
					// Annoying logic, but necessary since the parser may not split at a tag
					// boundary so we need to know if we're already in some data block and append.
					// If not we can safely start a new xmldata node for this tag
					if ($this->exists) {
						$this->xmldata['{'.$this->tag_prefix.$this->xmltag.'}'] .= $this->cdelim.$data;
					} else {
						$this->xmldata['{'.$this->tag_prefix.$this->xmltag.'}'] .= $data;
					}
				} else {
					if ($this->exists) {
						$this->xmldata['{'.$this->tag_prefix.$this->xmltag.'}'] .= $this->cdelim.$data;
					} else {
						$this->xmldata['{'.$this->tag_prefix.$this->xmltag.'}'] = $data;
					}
				}

				// Copy the tag to any duplicate (alias) nodes
				if (array_key_exists($this->xmltag, $this->byfields)) {
					foreach($this->byfields[$this->xmltag] as $copyfield) {
						if (!isset($this->xmldata['{'.$this->tag_prefix.$copyfield.'}'])) {
							$this->xmldata['{'.$this->tag_prefix.$copyfield.'}'] = $this->xmldata['{'.$this->tag_prefix.$this->xmltag.'}'];
						}
					}
				}
				$this->indata = true;
			}
		}
	}

	// Create any attribute nodes
	private function smd_xml_store_attribs($name) {
		if ($this->xmlatts[$name]) {
			foreach ($this->xmlatts[$name] as $xkey => $xval) {
				// Append if attribute previously encountered
				if ($this->concat && isset($this->xmldata['{'.$this->tag_prefix.$name.$this->tdelim.$xkey.'}'])) {
					$this->xmldata['{'.$this->tag_prefix.$name.$this->tdelim.$xkey.'}'] .= $this->cdelim.$xval;
				} else {
					$this->xmldata['{'.$this->tag_prefix.$name.$this->tdelim.$xkey.'}'] = $xval;
				}
			}
		}
	}
}


/*

// JSON reader from tumblr: adapt and genericise?

$offset = $_GET['offset'];
$ch = curl_init();
curl_setopt($ch,CURLOPT_URL,"http://blog.dgovil.com/api/read/json?num=10&start=".$offset);
curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
$result = curl_exec($ch);
curl_close($ch);

$result = str_replace("var tumblr_api_read = ","",$result);
$result = str_replace(';','',$result);
$result = str_replace('\u00a0','&amp;nbsp;',$result);

$jsondata = json_decode($result,true);
$posts = $jsondata['posts'];


foreach($posts as $post){   ?>
<div class="tumblr_post post-<?php echo $post['type'] ?>">

    <?php if ($post['type'] == 'regular') { ?>
        <div class="post-title" id="post-<?php echo $post['id'];?>"><a href="<?php echo $post['url-with-slug']; ?>"><?php echo $post{'regular-title'}; ?></a></div>
    <?php echo $post{'regular-body'}; ?>
      <?php } ?>

    <?php if ($post['type'] == 'quote') {  ?>
        <?php echo $post{'quote-text'}; ?>
        <?php echo $post{'quote-source'}; ?>
      <?php } ?>


    <?php if ($post['type'] == 'photo') {  ?>
        <img src="<?php echo $post['photo-url-500'];?>">
        <?php echo $post{'photo-caption'}; ?>
        <?php echo $post{'photo-set'}; ?>

        <a href="<?php echo $post{'photo-url'}; ?>" class="fImage">View Full Size</a>
    <?php } ?>

    <?php if ($post['type'] == 'link') {  ?>

        <p><a href="<?php echo $post{'link-url'}; ?>"><?php echo $post{'link-text'}; ?></a>
        <?php echo $post{'link-description'}; ?>
      <?php } ?>

    <?php if ($post['type'] == 'conversation') {  ?>
        <?php echo $post{'conversation-text'}; ?>
      <?php } ?>


    <?php if ($post['type'] == 'video') {  ?>
        <!--<?php echo $post{'video-source'}; ?>-->
        <?php echo $post{'video-player'}; ?>
        <?php echo $post{'video-caption'}; ?>
      <?php } ?>

    <?php if ($post['type'] == 'conversation') {  ?>
        <?php echo $post{'audio-caption'}; ?>
        <?php echo $post{'audio-player'}; ?>
        <?php echo $post{'audio-plays'}; ?>
      <?php } ?>

<div id="post-date">
<?php echo date("jS D M, H:i",strtotime($post['date'])); ?>&nbsp; &nbsp;<a href="<?php echo $post['url-with-slug']; ?>">Read on Tumblr</a>
</div>

</div>

*/
# --- END PLUGIN CODE ---
if (0) {
?>
<!--
# --- BEGIN PLUGIN HELP ---
h1. smd_xml

Yank bits out of any hunk of XML and reformat it to your own needs. Great for pulling feed info into your Textpattern site, for example from delicious.com.

h2. Features

* Specify your XML data from any URL -- internal or external to TXP -- or from a string
* Optionally process XML data using XSLT
* Selectively extract any items in your record set
* Use a Form or the plugin container to output data you have extracted
* XML tag attributes are available as well
* Supports pagination of results with limit/offset

h2(#author). Author

"Stef Dawson":http://stefdawson.com/contact. For other software by me, or to make a donation, see the "software page":http://stefdawson.com/sw.

h2(#install). Installation / Uninstallation

p(required). Requires PHP 5.2+ (and the SOAP extension for SOAP data feeds)

Download the plugin from either "textpattern.org":http://textpattern.org/plugins/1138/smd_xml, or the software page above, paste the code into the TXP Admin -> Plugins pane, install and enable the plugin. Visit the "forum thread":http://forum.textpattern.com/viewtopic.php?id=32718 for more info or to report on the success or otherwise of the plugin.

To remove the plugin, simply delete it from the Admin->Plugins tab.

h2. Tag: smd_xml

Place a @<txp:smd_xml>@ tag where you wish to process XML data -- this could be from a feed. Since this plugin is best explained by example, assume the following XML document is presented to the plugin:

bc(block). <employees>
   <employee>
      <name id="wile_e_coyote">Wile E. Coyote</name>
      <job_title>Schemer</job_title>
      <dept>ACME corp</dept>
      <quality>Cunning</quality>
      <quality>Deviousness</quality>
      <quality>Persistence</quality>
      <inventions>
         <name>ACME Rocket Sled</name>
         <name>ACME Super Cannon</name>
         <name>ACME Jetpack</name>
      </inventions>
   </employee>
   <employee>
      <name id="road_runner">Road Runner</name>
      <job_title>Seed expert</job_title>
      <dept>Evasion</dept>
      <quality>Speed</quality>
      <quality>Meep meep</quality>
   </employee>
</employees>

Use the following attributes to configure the smd_xml plugin (shaded attributes are mandatory) :

h3. Data import attributes

; %(atnm mand)data%
: The XML data source. Most of the time this will be a URL, though you could hard-code the XML data to use another TXP tag here (e.g. @<txp:variable />@).
; %(atnm mand)record%
: The name of the XML tag that surrounds each record of data in your feed. Thus you would need @record="employee"@ in the above document.
; %(atnm)fields%
: List of XML nodes you want to extract from each record. For example, @fields="name, dept"@.
: Each field you specify here will create a similarly-named "replacement tag":#reps that you may use in your form/container to display the relevant piece(s) of data. In this case, @{name}@ and @{dept}@ would be available in your output.
: You may extract multiple copies of the same field by separating the name of the field's copy with @param_delim@. For example: @fields="pubDate, title|url_ttl, id, link"@ would extract title twice: once as @{title}@ and again as @{url_ttl}@. See "example 6":#eg6 for a practical application.
: Finally, you can extract specific items based on their hierarchy in the tree. For example, if you specified @field="name"@ on the above document you would retrieve the concatenation of the employee and invention 'name' nodes. If you wanted to only extract the names of the inventions, you would specify @field="inventions->name"@. Similarly, if you only wanted the employee name you would use @field="employee->name"@. Chain nodes together with as many @->@ connectors as necessary to suit your XML stream.
; %(atnm)datawrap%
: Sometimes the incoming XML document is just a series of records without any container. This can cause the plugin to get confused under certain circumstances. If you find this happening, use this attribute to manually wrap your data in the given XML tag. e.g. @datawrap="my_records"@ would wrap the data stream with @<my_records> ... </my_records>@ tags.
: This attribute is also used as the default SOAP wrapper.
; %(atnm)load_atts%
: When field attributes are detected they can be made available either when the start tag is encountered, or when the corresponding end tag is found. Options:
:: *start*
:: *end*
: Default: @start@
; %(atnm)match%
: Consider nodes if its data matches this given regular expression. Specify as many matches as you like, each separated by @delim@. A match must comprise two elements:
:: The tag name to consider.
:: The full regular expression (including delimiters) to compare the data in that tag against.
; %(atnm)skip%
: List of XML nodes you want to skip over in each record. Useful if a field you wish to extract is used in two places in the same record. See "example 2":#eg2 for a practical application.
; %(atnm)defaults%
: List of default values you wish to set if any @fields@ are not set in your document. Specify defaults in pairs of entries like this: @defaults="field|default, field|default, ..."@.
: The pipe can be altered with @param_delim@.
; %(atnm)set_empty%
: Any fields that are not set in your document will normally mean that you'll see the raw @{replacement tag}@ in your output. Use @set_empty="1"@ to ensure that all empty nodes are set to an empty value. Any @defaults@ you specify will take precedence over empties.
; %(atnm)cache_time%
: If set, the XML document is cached in the TXP prefs. Subsequent calls to smd_xml (e.g. refreshing the page) will read the cached information instead of hitting the @data@ URL, thus cutting down on network traffic.
: After @cache_time@ (specified in seconds) has elapsed, the next page refresh will cause the document to be fetched from the @data@ URL again. You may, however, force a refresh from the data URL at any time by adding @&force_read=1@ to the browser URL (you can use smd_prefalizer and search for 'smd_xml' to find the cached documents -- each is referenced by its unique ID)

h3. Manipulation attributes

; *kill_spaces*
: Remove all inter-tag whitespace, newlines and tabs, i.e. redundant spaces surrounding the tags in the stream. It does not touch spaces within nodes.
: Although optional, this attribute is *highly* recommended as it has the side effect of usually speeding up the parsing process. It does, however, make the feed very difficult to read as it squishes it all up on one line. So consider turning this off if you are debugging. Options:
:: 0: no, keep inter-tag spaces in the feed
:: 1: yes, remove them
: Default: 1
; %(atnm)transform%
: Perform tranformations to the raw data stream. The transformations occur prior to the data being cached so the results are cached as well. Specify as many transformations as you like, each separated by @delim@. Each transformation is broken down into a class (type) and a list of parameters for that class, all separated by @param_delim@. You can choose from the following classes of transform:
:: *xsl*: the second parameter is the URL of the XSL stylesheet to fetch, e.g. @transform="xsl|http://site.com/path/to/stylesheet.xslt"@.
:: *replace*: swap portions of the document that match the (full, including delimiters) regular expression given in the second parameter with the value given in the third. If the third parameter is omitted, the matching content is removed. e.g. @transform="replace|%<xs:schema.+?<\/xs:schema>%"@.
; %(atnm)format%
: Alter the format of this list of fields. For each field, specify items separated by @param_delim@: The first is the name of the field you want to alter; The 2nd is the type of alteration required; The 3rd|4th|5th|.. specify how you want to alter the data. The following data types are supported:
:: %(atnm)case% : alter the case of the field. The items may be cumulative. Choose from four options as the third, fourth, etc parameters:
::: *upper*
::: *lower*
::: *ucfirst*
::: *ucwords*
:: Example: to first convert the field to lower case then convert the first letter of each word to upper case, use @format="Country|case|lower|ucwords"@
:: %(atnm)date% : takes one argument; the format string as detailed in "strftime":http://php.net/manual/en/function.strftime.php. Example: @format="pubDate|date|%d %B %Y %H:%I:%S"@ would reformat the pubDate field. Can also be used to reformat time strings.
:: %(atnm)escape% : escape the field so special characters are encoded as their HTML entity values. Options:
::: *double_quotes*: encode only double quotes (default)
::: *all_quotes*: encode both double and single quotes
::: *no_quotes*: don't encode any double or single quotes
:: %(atnm)fordb% : harden the field so it can be used in an SQL statement.
:: %(atnm)link% : convert the URL in this field to an HTML anchor hyperlink. Example: @format="cat_url|link"@ (replaces the @linkify@ attribute from the v0.2x plugin versions).
:: %(atnm)sanitize% : convert the field into one of three 'dumed down' formats, as specified by the third parameter. Choose from:
::: *url* for creating simple, valid URL strings
::: *file* for creating valid file names
::: *url_title* for making TXP-style URL titles as governed by your prefs settings
:: Example: @format="Title|sanitize|url"@ to sanitize the Title field suitable for use in a web address
: NOTE: format only applies to the form/container content. It is NOT applicable in @ontag@ Forms. If you wish to apply formatting to ontag attributes, or perform more complicated transformations, consider the smd_wrap plugin.
; %(atnm)target_enc%
: Character encoding to apply to the parsed XML data. Choose from:
:: *ISO-8859-1*
:: *US-ASCII*
:: *UTF-8*
: Default: @UTF-8@.
; %(atnm)uppercase%
: Set to 1 to force all XML tag names to be in upper case, thus you would have to specify @fields="NAME, DEPT"@ in order to successfully extract those fields.
; %(atnm)concat%
: Any duplicate nodes in the stream are usually concatenated together. If you wish to turn this feature off so that only the last tag's content remains, set @concat="0"@.
: Default: 1
; %(atnm)convert%
: If your data stream contains data you don't want or data that you wish to translate (for example, character entities) you can list them here.
: Items are specified in pairs separated by @param_delim@; the first is the item to search for and the second is its replacement.
: For example: @convert="&amp;#039|'"@ would replace all occurrences of @&amp;#039@ with an apostrophe character. Note that the replacements are performed on the raw stream _before_ it is parsed and _after_ it is cached. Also take care when decoding double quotes; this is the correct method: @convert="&amp;quot;|"""@ (note the double quote is escaped by putting _two_ double quote characters in)

h3. Forms and paging attributes

; %(atnm)form%
: The Txp Form with which to parse each record. You may use the plugin as a container instead if you prefer.
; %(atnm)pageform%
: Optional Txp form used to specify the layout of any paging navigation and statistics such as page number, quantity of records per page, total number of records, etc. See "paging replacement tags":#pgreps.
; %(atnm)pagepos%
: The position of the paging information. Options are @below@ (the default), @above@, or both of them separated by @delim@.
; %(atnm)limit%
: Show this many records per page. Setting a @limit@ smaller than the total number of records switches paging on automatically so you can use the @<txp:older />@ and @<txp:newer />@ tags inside your @pageform@ to step through each page of results.
: You may also construct your own paging (see "example 3":#eg3)
; %(atnm)offset%
: Skip this many records before outputting the results.
: If you specify a negative @offset@ you start that many records from the end of the document
; %(atnm)pagevar%
: If you are putting smd_xml on the same page as a standard article list, the built-in newer and older tags will clash with those of smd_xml; clicking next/prev will step through both your result set and your article list.
: Specify a different variable name here so the two lists can be navigated independently, e.g. @pagevar="xpage"@.
: Note that if you change this, you will have to generate your own custom newer/older links (see "example 4":#eg4) and the "conditional tags":#smd_xif.
: There is also a special value @SMD_XML_UNIQUE_ID@ which assigns the tags' unique ID as the paging variable. See "example 5":#eg5 for more.
: Default: @pg@.
; %(atnm)ontagstart / ontagend%
: Under normal operation, each time the plugin encounters a node that matches one of your @fields@ it is extracted and the output stored for display _at the end of processing the entire document_. Sometimes you might wish to output information on-the-fly as the document is read. This is where @ontagstart@ and its companion @ontagend@ can help.
: Specify as many ontag items as you like, each separated by a comma. Within each ontag item you first specify the name of a Txp Form that will determine what to do or display when the tag is encountered. The remaining items (each separated by @param_delim@) are the tag names to "watch".
: Whenever one of the given tags is encountered (start of node or end of node depending on which ontag you have chosen) control is immediately passed to the relevant Form.
: Note that you may not use the node's data @{replacement}@ value unless using @ontagend@ (because its value has not been discovered at tag start!) You may, however, use any attribute values if you have set @load_atts="start"@.
: You canot use the @format@ attribute in your ontag Forms: consider the smd_wrap plugin if you need additional processing.

h4. Tag/class/formatting attributes

; %(atnm)wraptag%
: The HTML tag, without brackets, to surround each record you output.
; %(atnm)break%
: The HTML tag, without brackets, to surround each field you output.
; %(atnm)class%
: The CSS class name to apply to the @wraptag@.

h4. Plugin customisation

; %(atnm)delim%
: The delimiter to use between items in the plugin attributes.
Default: @,@ (comma).
; %(atnm)param_delim%
: The delimiter to use between items in XML and plugin data attributes.
: Default: @|@ (pipe).
; %(atnm)concat_delim%
: The delimiter to use between identically-named tags in the XML data stream.
Default: @ @ (space).
; %(atnm)var_prefix%
: If you wish to embed an smd_xml tag inside the container of another, the replacement and paging variables might clash. Use this in one of your tags to help prevent this.
: It takes up to two values separated by a comma: the first is the prefix to apply to regular replacement tags; the second is the prefix to apply to page-based replacement tags.
: If only one value is specifed, the same prefix will be applied to both tag and page replacements.
: Default: @, smd_xml_@ (i.e. no tag prefix, and @smd_xml_@ page prefix)
; %(atnm)timeout%
: The time in seconds to wait for the remote server to respond before giving up.
: Default: 10
; %(atnm)transport%
: (should not be needed) If you would like to force the plugin to use a particular HTTP transport mechanism to fetch your @data@ you can specify it here. Choose from:
:: *fsock*
:: *curl*
:: *soap*
: The @soap@ mechanism uses CURL internally so you must have that available.
: Default: @fsock@.
; %(atnm)transport_opts%
: When using @soap@ transport you often need to pass additional parameters to the SOAP server. @transport_opts@ takes up to three paramaters, separated by @delim@:
:: Client method: the name of a SOAP method to call
:: Data: a series of name-val pairs (separated by @param_delim@) or an XML document which will be passed to the client method. e.g. @type|table|user|Bloke|pass|wilecoyote@ passes three params (type, user, and pass) with corresponding values. Note that if you want to use XML here you need to declare your intention using the @transport_config@ attribute.
:: Result method: the name of a SOAP method to fetch the output. The first @param_delim@ option is the method name to call to obtain the result set, and the second is the portion of the results you want returned (e.g. @any@)
; %(atnm)transport_config%
: Allows you to configure how the plugin interacts with the server. The following configuration parameters are available; separate each configuration item from its predecessor using @delim@ and separate any value from its parameter name using @param_delim@ :
:: *soap_wrap* : the data you pass to the SOAP server may not be encapsulated in its own unique element. If that's the case and the server requires this, you can specify the wrapper here. For example, some servers require @soap_wrap|Request@.
:: *soap_delim* : when retrieving multiple SOAP items, they will be concatenated together using this delimiter. Default: the same delimiter as set in @param_delim@.
:: *soap_type_input* : can be either @nvpairs@ (the default, as shown above) or @xml@ if you are passing in a complete XML document to configure the SOAP server. When using xml input format, the plugin automatically converts the given XML document into a SOAP array.
:: *soap_type_output* : SOAP data is normally returned as an XML document, but if for some reason the server sends back a raw SOAP array you can use this with an @xml@ parameter to ask the plugin to try and interpret the SOAP data into an XML stream for you. The success of this operation is duty bound by how well formed the resulting data is. If using this you may (probably will) also need to specify @soap_numeric_wrap@.
:: *soap_numeric_wrap* : when converting a SOAP array back to XML, any repeating records are normally indexed starting from 0. Since raw numbers are invalid XML tag names they need to be altered somehow. By default, this is done by taking the parent class and appending a sequential number to it. If you wish to set any numeric records to a specific wrapper element, specify that element here.
; %(atnm)line_length%
: If you are using the @fsock@ transport mechanism, the plugin grabs the XML document line by line and uses a maximum line length of 8192 characters by default. This is usually good enough because most feeds contain newlines, but some (e.g. Google Spreadsheet) don't have any newlines in them.
: To successfully parse such documents you may need to increase the line length. In these situations, however, it is highly recommended to switch to @transport="curl"@ instead (if you can) because it does not have any line length restrictions.
; %(atnm)hashsize%
: (should not be needed) When specifying a @cache_time@ the plugin assigns a 32-character, unique reference to the current smd_xml based on your import attributes. @hashsize@ governs the mechanism for making this long reference shorter.
: It comprises two numbers separated by a colon; the first is the length of the uniqe ID, the second is how many characters to skip past each time a character is chosen. For example, if the unique_reference was @0cf285879bf9d6b812539eb748fbc8f6@ then @hashsize="6:5"@ would make a 6-character unique ID using every 5th character; in other words @05f898@. If at any time, you "fall off" the end of the long string, the plugin wraps back to the beginning of the string and continues counting.
Default: @6:5@.

h3(#reps). Replacement tags

Each XML field you extract from your data stream has an equivalently-named replacement tag available so you may use it anywhere you like in your Form/container. Although the examples here don't demonstrate this, the replacement names will be prefixed by whatever you have set in your @var_prefix@ attribute.

If you chose to extract @fields="name, job_title, quality"@ you would have the following replacement tags available during the first record:

* @{name}@ : Wile E. Coyote (+ the names of the Inventions)
* @{name|id}@ : wile_e_coyote
* @{job_title}@ : Schemer
* @{quality}@ : Cunning Deviousness Persistence

And during the second record, the same replacement tag names would refer to the following items:

* @{name}@ : Road Runner
* @{name|id}@ : road_runner
* @{job_title}@ : Seed expert
* @{quality}@ : Speed Meep meep

Note that the attribute called @id@ that is part of the @<name>@ XML tag has been extracted and is made available automatically. By default, the names of attributes are defined as @{tag|attribute}@. The pipe can be altered using @param_delim@.

The @{quality}@ tag appears more than once in the example document above and is thus concatenated by default. You can influence its output using the @concat@ and @concat_delim@ attributes, e.g. using @concat_delim="|"@ would render the following replacement variable on the first record:

* @{quality}@ : Cunning|Deviousness|Persistence

while @concat="0"@ would render this (i.e. the value of the last node encountered):

* @{quality}@ : Persistence

There are also some special statistical tags available in each record:

* @{smd_xml_totalrecs}@ : the total number of records found in your XML document
* @{smd_xml_pagerecs}@ : the number of records on this page (if not using paging, this is the same as above)
* @{smd_xml_pages}@ : the number of available pages
* @{smd_xml_thispage}@ : the page number of the currently visible page
* @{smd_xml_thisrec}@ : the record number, starting at 1
* @{smd_xml_thisindex}@ : the record number, starting at 0
* @{smd_xml_runrec}@ : the record number, starting at 1 and including any offset
* @{smd_xml_runindex}@ : the record number, starting at 0 and including any offset

h3(#pgreps). Paging replacement tags

In your @pageform@ you can employ any of the following replacement tags to build up a navigation system for stepping through your XML document. Note that they all show @smd_xml_@ as the prefix here, but that may be changed with the @var_prefix@ attribute:

* @{smd_xml_totalrecs}@ : the total number of records found in your XML document
* @{smd_xml_pagerecs}@ : the number of records on this page
* @{smd_xml_pages}@ : the number of available pages
* @{smd_xml_prevpage}@ : the page number of the previous page -- empty if on first page
* @{smd_xml_thispage}@ : the page number of the current page
* @{smd_xml_nextpage}@ : the page number of the next page -- empty if on last page
* @{smd_xml_rec_start}@ : the record number of the first record on this page (counted from the start of the record set)
* @{smd_xml_rec_end}@ : the record number of the last record on this page (counted from the start of the record set)
* @{smd_xml_recs_prev}@ : the number of records on the previous page
* @{smd_xml_recs_next}@ : the number of records on the next page
* @{smd_xml_unique_id}@ : the unique reference number assigned to this smd_xml tag (see "example 5":#eg5 for usage of this)

h2(#smd_xif). Tags: @<txp:smd_xml_if_prev>@ / @<txp:smd_xml_if_next>@

Use these container tags to determine if there is a next or previous page and take action if so. Can only be used inside @pageform@, thus all "paging replacement variables":#pgreps are available inside these tags.

bc(block). <txp:smd_xml_if_prev>Previous page</txp:smd_xml_if_prev>
<txp:smd_xml_if_next>Next page</txp:smd_xml_if_next>

The tags supprt @<txp:else />@. See "example 5":#eg5 for more.

h2. Examples

h3(#eg1). Example 1: delicious links

Swap @roadrunner@ in this code with your delicious username to get your own feed:

bc(block). <txp:smd_xml data="http://feeds.delicious.com/v2/rss/roadrunner"
     record="item" fields="title, link, pubDate, description"
     wraptag="dl">
   <dt><a href="{link}">{title}</a></dt>
   <dd>Posted: {pubDate}<br />{description}</dd>
</txp:smd_xml>

h3(#eg2). Example 2: twitter feed

bc(block). <txp:smd_xml
     data="http://twitter.com/statuses/user_timeline/textpattern.xml"
     record="status" fields="id, text, created_at" skip="user"
     wraptag="ul" format="text|link">
   <li>
      <a href="http://twitter.com/textpattern/statuses/{id}">
         {created_at}
      </a>
      <br />{text}
   </li>
</txp:smd_xml>

Notice that we @skip@ the whole _user_ block in the XML data stream. This is for two reasons:

# it is redundant information that appears in every record -- we already know to which user the feed belongs because they're all from the same user
# _created_at_ is used inside the user block as well as in the outer status block so we get two datestamps, which is not what we want (if we simply used @concat="0"@ to only grab one of the created_at entries, the last one would prevail -- the one from the user block)

h3(#eg3). Example 3: limit and paging

Viewing the I Love TXP feed 3 records at a time. Note that since the site is not updated frequently, the cache_time of 86400 seconds (1 day) is ample to avoid hammering the network:

bc(block). <txp:smd_xml
     data="http://feeds.feedburner.com/welovetxp"
     record="item" fields="title,description, link, pubDate"
     wraptag="ul" limit="3" pageform="pager"
     cache_time="86400">
   <li>
      <a href="{link}">
         {title}
      </a><span class="published">{pubDate}</span>
      <br />{description}
   </li>
</txp:smd_xml>

And in form @pager@:

bc(block). Page {smd_xml_thispage} of {smd_xml_pages}
<txp:newer>Previous page</txp:newer>
<txp:older>Next page</txp:older>

If you wanted to view the last three entries in the feed instead of the first three, you could set @offset="-3"@.

h3(#eg4). Example 4: using @pagevar@

Adding @pagevar="xmlpg"@ to example 3 allows paging independently of txp:older and txp:newer tags. You then need to build your own links in your @pager@ form, like this:

bc(block). Page {smd_xml_thispage} of {smd_xml_pages} |
   Showing records {smd_xml_rec_start} to {smd_xml_rec_end}
   of {smd_xml_totalrecs} |
  <a href="?xmlpg={smd_xml_prevpage}">Previous {smd_xml_recs_prev}</a>
  <a href="?xmlpg={smd_xml_nextpage}">Next {smd_xml_recs_next}</a>

That creates links to next and previous record sets using the assigned @pagevar@ as the URL parameter.

h3(#eg5). Example 5: conditional navigation and the unique ID

Again using example 3, if you used @pagevar="SMD_XML_UNIQUE_ID"@ the pagevar would be assigned the value @f290b8@. In this case we could use it like this:

bc(block). Page {smd_xml_thispage} of {smd_xml_pages} |
   Showing records {smd_xml_rec_start} to {smd_xml_rec_end}
   of {smd_xml_totalrecs} |
<txp:smd_xml_if_prev>
  <a href="?{smd_xml_unique_id}={smd_xml_prevpage}">Previous {smd_xml_recs_prev}</a>
</txp:smd_xml_if_prev>
<txp:smd_xml_if_next>
  <a href="?{smd_xml_unique_id}={smd_xml_nextpage}">Next {smd_xml_recs_next}</a>
</txp:smd_xml_if_next>

Note that we are using the conditional tags to only display the next and previous links if the next/prev page exists and also that the URL link is generated using @{smd_xml_unique_id}@. You could conceivably use this same pageform on more than one XML feed on the same page and navigate the two feeds indpendently, though you would have to work out a clever way of amalgamating the URL vars (perhaps using the adi_gps plugin).

h3(#eg6). Example 6: inserting XML data into TXP

bc(block). <txp:smd_xml data="http://feeds.delicious.com/v2/rss/roadrunner"
     record="item" fields="title|utitle, link, pubDate, description, category"
     format="pubDate|date|%Y-%m-%d %H:%I:%S,
     description|fordb, title|fordb, utitle|sanitize|url_title">
   <txp:smd_query query="INSERT INTO textpattern
     SET Posted='{pubDate}', LastMod=NOW(),
     url_title='{utitle}',
     Title='{title}', custom_3='{link}',
     Body='{description}', Body_html='{description}',
     Section='links', Category1='delicious',
     keywords='{category}'" />
</txp:smd_xml>

This example takes a delicious feed, reformats the various entries and inserts them into the textpattern table in a dedicated section. Note that the date format is altered and the feed's title is converted to a sanitized TXP URL suitable for the url_title field.

h2. Credits

This plugin would not have been possible without the tireless help from those community members willing to test my flaky beta code as I strive to make the plugin work across as many types of feed as possible. Special mentions, in no particular order, go to oliverker, aslsw66, tye, jakob, Mats, and Destry.

h2(changelog). Changelog

* 03 Apr 2012 | 0.40 | Improved feed support and tag detection for more varied / complicated feeds ; added XML-over-FTP support (thanks aslsw66) ; added SOAP transport facility, @transport_opts@ and @transport_config@ attributes ; added XSL and regex transform support ; allowed @sub->field@ support and added @match@, @ontagstart@, @ontagend@ and @load_atts@ for finer control over field extraction ; added @datawrap@, @var_prefix@ and @timeout@ attributes ; added record attribute support (thanks Mats) ; fixed mangled date field bug ; fixed attributes-in-record-entry limit bug and undesired ontag output (both thanks tye) ; changed @format@'s @escape@ attribute to @fordb@ (@escape@ is now for @htmlspecialchars()@) ; added @kill_spaces@ so inter-tag whitespace removal is optional (but highly recommended) ; added @tag_delim@ (thanks MattD)
* 17 Jan 2010 | 0.30 | Enabled URL params to be passed in the @data@ attribute ; added @format@ ; deprecated @linkify@ ; @param_delim@ default is now pipe
* 13 Jan 2010 | 0.22 | Added @line_length@ (thanks nardo)
* 05 Jan 2010 | 0.21 | Supports https:// feeds (thanks photonomad) ; added @transport@, @defaults@ and @set_empty@ attributes
* 03 Jan 2010 | 0.20 | Added cache support (thanks variaas) ; added @limit@, @offset@ and paging features ; added @linkify@ (thanks Jaro)
* 02 Jan 2010 | 0.10 | Initial release
# --- END PLUGIN HELP ---
-->
<?php
}
?>