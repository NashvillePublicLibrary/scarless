<?php

// echo 'SYNTAX: path/to/php NashvilleCarlXPatronLoader.php, e.g., $ sudo /opt/rh/php55/root/usr/bin/php NashvilleCarlXPatronLoader.php\n';
// 
// TO DO: update guarantor note
// TO DO: guarantor notes only get inserted for appropriate age students. or all of em?
// TO DO: update PIN. First ask: should we?
//	In cases where staff are... staff: I assume: yes
//	In cases where student patron record has been merged with another account: I assume: yes
//	Ergo, yes?
// TO DO: logging
// TO DO: Images aren't uploading after test 2018 06 18...
// TO DO: retry after oracle connect error
// TO DO: review oracle php error handling https://docs.oracle.com/cd/E17781_01/appdev.112/e18555/ch_seven_error.htm#TDPPH165
// TO DO: capture other patron api errors, e.g., org.hibernate.exception.ConstraintViolationException: could not execute statement; No matching records found
// TO DO: consider whether to make the SQL write the SOAP into a single table
// TO DO: STAFF
// TO DO: for patron data privacy, kill data files when actions are complete

date_default_timezone_set('America/Chicago');
$startTime = microtime(true);

require_once 'PEAR.php';

$configArray		= parse_ini_file('../config.pwd.ini', true, INI_SCANNER_RAW);
$carlx_db_php		= $configArray['Catalog']['carlx_db_php'];
$carlx_db_php_user	= $configArray['Catalog']['carlx_db_php_user'];
$carlx_db_php_password	= $configArray['Catalog']['carlx_db_php_password'];
$patronApiWsdl		= $configArray['Catalog']['patronApiWsdl'];
$patronApiDebugMode	= $configArray['Catalog']['patronApiDebugMode'];
$patronApiReportMode	= $configArray['Catalog']['patronApiReportMode'];
$reportPath		= '../data/';

function callAPI($wsdl, $requestName, $request) {
	$connectionPassed = false;
	$numTries = 0;
	$result = new stdClass();
	$result->response = "";
	while (!$connectionPassed && $numTries < 3) {
		try {
			$client = new SOAPClient($wsdl, array('connection_timeout' => 3, 'features' => SOAP_WAIT_ONE_WAY_CALLS, 'trace' => 1));
			$result->response = $client->$requestName($request);
			$connectionPassed = true;
			if (is_null($result->response)) {$result->response = $client->__getLastResponse();}
			if (!empty($result->response)) {
				if (gettype($result->response) == 'object') {
					$ShortMessage[0] = $result->response->ResponseStatuses->ResponseStatus->ShortMessage;
					$result->success = $ShortMessage[0] == 'Successful operation';
				} else if (gettype($result->response) == 'string') {
					$result->success = stripos($result->response, '<ns2:ShortMessage>Successful operation</ns2:ShortMessage>') !== false;
					preg_match('/<ns2:LongMessage>(.+?)<\/ns2:LongMessage>/', $result->response, $longMessages);
					preg_match('/<ns2:ShortMessage>(.+?)<\/ns2:ShortMessage>/', $result->response, $shortMessages);
				}
				if(!$result->success) {
					$result->error = "$request->SearchID : Failed" . (isset($longMessages[1]) ? ' : ' . $longMessages[1] : (isset($shortMessages[0]) ? ' : ' . $shortMessages[0] : ''));
				}
			} else {
				$result->error = "$request->SearchID : Failed : No SOAP response from API.";
			}
		} catch (SoapFault $e) {
			if ($numTries == 2) { $result->error = "$request->SearchID : Exception : " . $e->getMessage(); }
		}
		$numTries++;
	}
	return $result;
}

// connect to carlx oracle db
$conn = oci_connect($carlx_db_php_user, $carlx_db_php_password, $carlx_db_php);
if (!$conn) {
	$e = oci_error();
	trigger_error(htmlentities($e['message'], ENT_QUOTES), E_USER_ERROR);
}

// get_patrons_mnps_carlx.sql
$get_patrons_mnps_carlx_filehandle = fopen("get_patrons_mnps_carlx.sql", "r") or die("Unable to open get_patrons_mnps_carlx.sql");
$sql = fread($get_patrons_mnps_carlx_filehandle, filesize("get_patrons_mnps_carlx.sql"));
fclose($get_patrons_mnps_carlx_filehandle);

$stid = oci_parse($conn, $sql);
// TO DO: consider tuning oci_set_prefetch to improve performance. See https://docs.oracle.com/database/121/TDPPH/ch_eight_query.htm#TDPPH172
oci_set_prefetch($stid, 10000);
oci_execute($stid);
// start a new file for the CarlX patron extract
$patrons_mnps_carlx_filehandle = fopen($reportPath . "patrons_mnps_carlx.csv", 'w');
while (($row = oci_fetch_array ($stid, OCI_ASSOC+OCI_RETURN_NULLS)) != false) {
	// CSV OUTPUT
	fputcsv($patrons_mnps_carlx_filehandle, $row);
}
fclose($patrons_mnps_carlx_filehandle);
echo "Patrons MNPS CARLX retrieved and written\n";
oci_free_statement($stid);
oci_close($conn);

// Using shell instead of php as per https://stackoverflow.com/questions/35999597/importing-csv-file-into-sqlite3-from-php#36001304
// FROM https://www.sqlite.org/cli.html:
// "The dot-commands are interpreted by the sqlite3.exe command-line program, not by SQLite itself."
// "So none of the dot-commands will work as an argument to SQLite interfaces like sqlite3_prepare() or sqlite3_exec()."

exec("bash format_patrons_mnps_infinitecampus.sh");
exec("sqlite3 ../data/ic2carlx.db < patrons_mnps_compare.sql");
echo "Infinitecampus vs. CarlX patron record comparison complete\n";

// CREATE CARLX PATRONS

$all_rows = array();
$fhnd = fopen("../data/patrons_mnps_carlx_create.csv", "r");
if ($fhnd){
	$header = fgetcsv($fhnd);
	while ($row = fgetcsv($fhnd)) {
		$all_rows[] = array_combine($header, $row);
	}
}
//print_r($all_rows);

foreach ($all_rows as $patron) {
	// TESTING
	if ($patron['PatronID'] > 190999115) { break; }
	// CREATE REQUEST
	$requestName							= 'createPatron';
	$request							= new stdClass();
	$request->Modifiers						= new stdClass();
	$request->Modifiers->DebugMode					= $patronApiDebugMode;
	$request->Modifiers->ReportMode					= $patronApiReportMode;
	$request->Patron						= new stdClass();
	$request->Patron->PatronID					= $patron['PatronID']; // Patron ID
	$request->Patron->PatronType					= $patron['Borrowertypecode']; // Patron Type
	$request->Patron->LastName					= $patron['Patronlastname']; // Patron Name Last
	$request->Patron->FirstName					= $patron['Patronfirstname']; // Patron Name First
	$request->Patron->MiddleName					= $patron['Patronmiddlename']; // Patron Name Middle
	$request->Patron->SuffixName					= $patron['Patronsuffix']; // Patron Name Suffix
	$request->Patron->Addresses					= new stdClass();
	$request->Patron->Addresses->Address[0]				= new stdClass();
	$request->Patron->Addresses->Address[0]->Type			= 'Primary';
	$request->Patron->Addresses->Address[0]->Street			= $patron['PrimaryStreetAddress']; // Patron Address Street
	$request->Patron->Addresses->Address[0]->City			= $patron['PrimaryCity']; // Patron Address City
	$request->Patron->Addresses->Address[0]->State			= $patron['PrimaryState']; // Patron Address State
	$request->Patron->Addresses->Address[0]->PostalCode		= $patron['PrimaryZipCode']; // Patron Address ZIP Code
	// $request->Patron->Phone1					= $patron['PrimaryPhoneNumber']; // Patron Primary Phone
	$request->Patron->Phone2					= $patron['SecondaryPhoneNumber']; // Patron Secondary Phone
	$request->Patron->DefaultBranch					= $patron['DefaultBranch']; // Patron Default Branch
	$request->Patron->LastActionBranch				= $patron['DefaultBranch']; // Patron Last Action Branch
	$request->Patron->LastEditBranch				= $patron['DefaultBranch']; // Patron Last Edit Branch
	$request->Patron->RegBranch					= $patron['DefaultBranch']; // Patron Registration Branch
	$request->Patron->Email						= $patron['EmailAddress']; // Patron Email
	$request->Patron->BirthDate					= $patron['BirthDate']; // Patron Birth Date as Y-m-d
	// Sponsor: Homeroom Teacher
	$request->Patron->Addresses->Address[1]				= new stdClass();
	$request->Patron->Addresses->Address[1]->Type			= 'Secondary';
	$request->Patron->Addresses->Address[1]->Street			= $patron['TeacherID']; // Patron Homeroom Teacher ID
	$request->Patron->SponsorName					= $patron['TeacherName'];
	// NON-CSV STUFF
	$request->Patron->EmailNotices					= 'send email';
	$request->Patron->ExpirationDate				= date_create_from_format('Y-m-d',$patron['ExpirationDate'])->format('c'); // Patron Expiration Date as ISO 8601
	$request->Patron->LastActionDate				= date('c'); // Last Action Date, format ISO 8601
	$request->Patron->LastEditDate					= date('c'); // Patron Last Edit Date, format ISO 8601
	$request->Patron->LastEditedBy					= 'PIK'; // Pika Patron Loader
	$request->Patron->PatronStatusCode				= 'G'; // Patron Status Code = GOOD
	$request->Patron->PreferredAddress				= 'Sponsor';
	$request->Patron->RegisteredBy					= 'PIK'; // Registered By : Pika Patron Loader
	$request->Patron->RegistrationDate				= date('c'); // Registration Date, format ISO 8601
//var_dump($request);
	$result = callAPI($patronApiWsdl, $requestName, $request);
	//var_dump($result);
	if (isset($result->error)) {
		echo "$result->error\n";
		$errors[] = $result->error;
	} else {
		echo $request->Patron->PatronID . " : created\n";
	}

// SET PIN FOR CREATED PATRON
// createPatron is not setting PIN as requested. See TLC ticket 452557
// Therefore we use updatePatron to set PIN
	// CREATE REQUEST
	$requestName							= 'updatePatron';
	$request							= new stdClass();
	$request->Modifiers						= new stdClass();
	$request->Modifiers->DebugMode					= $patronApiDebugMode;
	$request->Modifiers->ReportMode					= $patronApiReportMode;
	$request->SearchType						= 'Patron ID';
	$request->SearchID						= $patron['PatronID']; // Patron ID
	$request->Patron						= new stdClass();
	if (stripos($patron['PatronID'],'190999') == 0) {
		$request->Patron->PatronPIN				= '7357';
	} else {
		$request->Patron->PatronPIN				= substr($patron['BirthDate'],5,2) . substr($patron['BirthDate'],8,2);
	}
//var_dump($request);
	$result = callAPI($patronApiWsdl, $requestName, $request);
	//var_dump($result);
	if (isset($result->error)) {
		echo "$result->error\n";
		$errors[] = $result->error;
	} else {
		echo $request->SearchID . " : PIN set\n";
	}

}

// UPDATE CARLX PATRONS

$all_rows = array();
$fhnd = fopen("../data/patrons_mnps_carlx_update.csv", "r");
if ($fhnd){
	$header = fgetcsv($fhnd);
	while ($row = fgetcsv($fhnd)) {
		$all_rows[] = array_combine($header, $row);
	}
}
//print_r($all_rows);

foreach ($all_rows as $patron) {
	// TESTING
	if ($patron['PatronID'] > 190999115) { break; }
	// CREATE REQUEST
	$requestName							= 'updatePatron';
	$request							= new stdClass();
	$request->Modifiers						= new stdClass();
	$request->Modifiers->DebugMode					= $patronApiDebugMode;
	$request->Modifiers->ReportMode					= $patronApiReportMode;
	$request->SearchType						= 'Patron ID';
	$request->SearchID						= $patron['PatronID']; // Patron ID
	$request->Patron						= new stdClass();
	$request->Patron->PatronType					= $patron['Borrowertypecode']; // Patron Type
	$request->Patron->LastName					= $patron['Patronlastname']; // Patron Name Last
	$request->Patron->FirstName					= $patron['Patronfirstname']; // Patron Name First
	$request->Patron->MiddleName					= $patron['Patronmiddlename']; // Patron Name Middle
	$request->Patron->SuffixName					= $patron['Patronsuffix']; // Patron Name Suffix
	$request->Patron->Addresses					= new stdClass();
	$request->Patron->Addresses->Address[0]				= new stdClass();
	$request->Patron->Addresses->Address[0]->Type			= 'Primary';
	$request->Patron->Addresses->Address[0]->Street			= $patron['PrimaryStreetAddress']; // Patron Address Street
	$request->Patron->Addresses->Address[0]->City			= $patron['PrimaryCity']; // Patron Address City
	$request->Patron->Addresses->Address[0]->State			= $patron['PrimaryState']; // Patron Address State
	$request->Patron->Addresses->Address[0]->PostalCode		= $patron['PrimaryZipCode']; // Patron Address ZIP Code
	// $request->Patron->Phone1					= $patron['PrimaryPhoneNumber']; // Patron Primary Phone
	$request->Patron->Phone2					= $patron['SecondaryPhoneNumber']; // Patron Secondary Phone
	$request->Patron->DefaultBranch					= $patron['DefaultBranch']; // Patron Default Branch
	$request->Patron->LastActionBranch				= $patron['DefaultBranch']; // Patron Last Action Branch
	$request->Patron->LastEditBranch				= $patron['DefaultBranch']; // Patron Last Edit Branch
	$request->Patron->RegBranch					= $patron['DefaultBranch']; // Patron Registration Branch
	$request->Patron->Email						= $patron['EmailAddress']; // Patron Email
	$request->Patron->BirthDate					= $patron['BirthDate']; // Patron Birth Date as Y-m-d
	// Sponsor: Homeroom Teacher
	$request->Patron->Addresses->Address[1]				= new stdClass();
	$request->Patron->Addresses->Address[1]->Type			= 'Secondary';
	$request->Patron->Addresses->Address[1]->Street			= $patron['TeacherID']; // Patron Homeroom Teacher ID
	$request->Patron->SponsorName					= $patron['TeacherName'];
	if (stripos($patron['PatronID'],'190999') == 0) {
		$request->Patron->PatronPIN				= '7357';
	} else {
		$request->Patron->PatronPIN				= substr($patron['BirthDate'],5,2) . substr($patron['BirthDate'],8,2);
	}
	// NON-CSV STUFF
	$request->Patron->ExpirationDate				= date_create_from_format('Y-m-d',$patron['ExpirationDate'])->format('c'); // Patron Expiration Date as ISO 8601
	$request->Patron->LastActionDate				= date('c'); // Last Action Date, format ISO 8601
	$request->Patron->LastEditDate					= date('c'); // Patron Last Edit Date, format ISO 8601
	$request->Patron->LastEditedBy					= 'PIK'; // Pika Patron Loader
	$request->Patron->PreferredAddress				= 'Sponsor';
//var_dump($request);
	$result = callAPI($patronApiWsdl, $requestName, $request);
	//var_dump($result);
	if (isset($result->error)) {
		echo "$result->error\n";
		$errors[] = $result->error;
	} else {
		echo "$request->SearchID : updated\n";
	}
}

// CREATE GUARANTOR NOTES
$all_rows = array();
$fhnd = fopen("../data/patrons_mnps_carlx_createNoteGuarantor.csv", "r") or die("unable to open ../data/patrons_mnps_carlx_createNoteGuarantor.csv");
if ($fhnd){
	$header = fgetcsv($fhnd);
	while ($row = fgetcsv($fhnd)) {
		$all_rows[] = array_combine($header, $row);
	}
}
//print_r($all_rows);
foreach ($all_rows as $patron) {
	// TESTING
	if ($patron['PatronID'] > 190999115) { break; }
	// CREATE REQUEST
	$requestName							= 'addPatronNote';
	$request							= new stdClass();
	$request->Modifiers						= new stdClass();
	$request->Modifiers->DebugMode					= $patronApiDebugMode;
	$request->Modifiers->ReportMode					= $patronApiReportMode;
	$request->Modifiers->StaffID					= 'PIK'; // Pika Patron Loader
	$request->Note							= new stdClass();
	$request->Note->PatronID					= $patron['PatronID']; // Patron ID
	$request->Note->NoteType					= 2; 
	$request->Note->NoteText					= 'NPL: MNPS Guarantor effective ' . date('Y-m-d') . ' - ' . $patron['Guarantor']; // Patron Guarantor as Note
//var_dump($request);
	$result = callAPI($patronApiWsdl, $requestName, $request);
	//var_dump($result);
	if (isset($result->error)) {
		echo "$result->error\n";
		$errors[] = $result->error;
	} else {
		echo $request->Note->PatronID . " : Guarantor note set\n";
	}
}

// CREATE USER DEFINED FIELDS ENTRIES

$all_rows = array();
$fhnd = fopen("../data/patrons_mnps_carlx_createUdf.csv", "r") or die("unable to open ../data/patrons_mnps_carlx_createUdf.csv");
if ($fhnd){
	$header = fgetcsv($fhnd);
	while ($row = fgetcsv($fhnd)) {
		$all_rows[] = array_combine($header, $row);
	}
}
//print_r($all_rows);
foreach ($all_rows as $patron) {
	// TESTING
	if ($patron['patronid'] > 190999115) { continue; }
	if ($patron['patronid'] > 190999115 && $patron['fieldid'] == 4) { break; }
	// CREATE REQUEST
	$requestName							= 'createPatronUserDefinedFields';
	$request							= new stdClass();
	$request->Modifiers						= new stdClass();
	$request->Modifiers->DebugMode					= $patronApiDebugMode;
	$request->Modifiers->ReportMode					= $patronApiReportMode;
	$request->PatronUserDefinedField				= new stdClass();
	$request->PatronUserDefinedField->patronid			= $patron['patronid'];
	$request->PatronUserDefinedField->occur				= $patron['occur'];
	$request->PatronUserDefinedField->fieldid			= $patron['fieldid'];
	$request->PatronUserDefinedField->numcode			= $patron['numcode'];
	$request->PatronUserDefinedField->type				= $patron['type'];
	$request->PatronUserDefinedField->valuename			= $patron['valuename'];
//var_dump($request);
	$result = callAPI($patronApiWsdl, $requestName, $request);
	//var_dump($result);
	if (isset($result->error)) {
		echo "$result->error\n";
		$errors[] = $result->error;
	} else {
		echo $request->PatronUserDefinedField->patronid . " : created udf" . $request->PatronUserDefinedField->fieldid . " value\n";
	}
}

// UPDATE USER DEFINED FIELDS ENTRIES

$all_rows = array();
$fhnd = fopen("../data/patrons_mnps_carlx_updateUdf.csv", "r") or die("unable to open ../data/patrons_mnps_carlx_updateUdf.csv");
if ($fhnd){
	$header = fgetcsv($fhnd);
	while ($row = fgetcsv($fhnd)) {
		$all_rows[] = array_combine($header, $row);
	}
}
//print_r($all_rows);
foreach ($all_rows as $patron) {
	// TESTING
	if ($patron['new_patronid'] > 190999115) { break; }
	// CREATE REQUEST
	$requestName							= 'updatePatronUserDefinedFields';
	$request							= new stdClass();
	$request->Modifiers						= new stdClass();
	$request->Modifiers->DebugMode					= $patronApiDebugMode;
	$request->Modifiers->ReportMode					= $patronApiReportMode;
	$request->OldPatronUserDefinedField				= new stdClass();
	$request->OldPatronUserDefinedField->patronid			= $patron['old_patronid'];
	$request->OldPatronUserDefinedField->occur			= $patron['old_occur'];
	$request->OldPatronUserDefinedField->fieldid			= $patron['old_fieldid'];
	$request->OldPatronUserDefinedField->numcode			= $patron['old_numcode'];
	$request->OldPatronUserDefinedField->type			= $patron['old_type'];
	$request->OldPatronUserDefinedField->valuename			= $patron['old_valuename'];
	$request->NewPatronUserDefinedField				= new stdClass();
	$request->NewPatronUserDefinedField->patronid			= $patron['new_patronid'];
	$request->NewPatronUserDefinedField->occur			= $patron['new_occur'];
	$request->NewPatronUserDefinedField->fieldid			= $patron['new_fieldid'];
	$request->NewPatronUserDefinedField->numcode			= $patron['new_numcode'];
	$request->NewPatronUserDefinedField->type			= $patron['new_type'];
	$request->NewPatronUserDefinedField->valuename			= $patron['new_valuename'];
//var_dump($request);
	$result = callAPI($patronApiWsdl, $requestName, $request);
	//var_dump($result);
	if (isset($result->error)) {
		echo "$result->error\n";
		$errors[] = $result->error;
	} else {
		echo $request->NewPatronUserDefinedField->patronid . " : updated udf" . $request->NewPatronUserDefinedField->fieldid . " value\n";
	}
}

// CREATE/UPDATE PATRON IMAGES
// if they were modified today

// currently the data sent by this script does not get caught by Carl correctly. I can send what I think is identical information via SOAPUI and Carl catches it,
// renders a good image in Carl.Connect. But the images sent by this script result in white fields.
// I should check the Carl logs to see whether I can see the incoming request and compare a SOAPUI request against one sent by this script
$iterator = new DirectoryIterator('../data/images');
$today = date_create('today')->format('U');
foreach ($iterator as $fileinfo) {
        $file = $fileinfo->getFilename();
        $mtime = $fileinfo->getMTime();
        if ($fileinfo->isFile() && preg_match('/^190\d{6}.jpg$/', $file) === 1 && $mtime >= $today) {
		$requestName						= 'updateImage';
		$request						= new stdClass();
		$request->Modifiers					= new stdClass();
		$request->Modifiers->DebugMode				= $patronApiDebugMode;
		$request->Modifiers->ReportMode				= $patronApiReportMode;
		$request->SearchType					= 'Patron ID';
		$request->SearchID					= substr($file,0,9); // Patron ID
		$request->ImageType					= 'Profile'; // Patron Profile Picture vs. Signature
		$imageFilePath 						= "../data/images/" . $file;
		if (file_exists($imageFilePath)) {
			$imageFileHandle 				= fopen($imageFilePath, "rb");
			$request->ImageData				= bin2hex(fread($imageFileHandle, filesize($imageFilePath)));
			fclose($imageFileHandle);
		} else {
// TO DO: create IMAGE NOT AVAILABLE image
		}

		if (isset($request->ImageData)) {
//var_dump($request);
			$result = callAPI($patronApiWsdl, $requestName, $request);
//var_dump($result);
			if (isset($result->error)) {
				echo "$result->error\n";
				$errors[] = $result->error;
			} else {
				echo "$request->SearchID : updated image\n";
			}
		}
	}
}

/*
// TO DO: UPDATE Guarantor notes
// Lane says the note should be like: 
// NPL: MNPS Guarantor effective 03/29/2017 - 7/31/2017: BOBBY BROWN
	// Note: Guarantor // Notes appears weird in the API // BE CAREFUL TO NOT OVERWRITE UNRELATED NOTES
	$request->Patron->Notes						= new stdClass();
	$request->Patron->Notes->NoteType				= 2; 
	$request->Patron->Notes->NoteText				= 'NPL: MNPS Guarantor: ' . $patron[27]; // Patron Guarantor Name
*/

/*
// VERIFY ALL UPDATED VALUES WERE UPDATED
	$request							= new stdClass();
	$request->Modifiers						= new stdClass();
	$request->Modifiers->DebugMode					= $patronApiDebugMode;
	$request->Modifiers->ReportMode					= $patronApiReportMode;
	$request->SearchType						= 'Patron ID';
	$request->SearchID						= $patron['PatronID']; // Patron ID
	try {
		$result = $client->getPatronInformation($request);
		$result = $client->__getLastResponse();
var_dump($result);
	} catch (Exception $e) {
		echo $e->getMessage();
	}

*/

?>
