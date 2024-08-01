<?php
// Database configuration
$serverName = "serviggo-sql-server.database.windows.net";
$connectionOptions = array(
    "Database" => "Serviggo_DB",
    "Uid" => "serviggo-admin",
    "PWD" => "0zk@nY@zg@n6!"
);

// Get the JSON input from the request
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Validate API Key
$expectedApiKey = "abc123";
if (!isset($data['API_Key']) || $data['API_Key'] !== $expectedApiKey) {
    echo json_encode(["status" => "error", "message" => "Invalid API Key"]);
    exit; // Terminate the script
}

// Check if Number_Caller is provided
if (!isset($data['Number_Caller'])) {
    echo json_encode(["status" => "error", "message" => "Number_Caller is required"]);
    exit;
}

$numberCaller = $data['Number_Caller'];

// Establish the connection
$conn = sqlsrv_connect($serverName, $connectionOptions);

// Check if connection was successful
if ($conn === false) {
    die(json_encode(["status" => "error", "message" => "Database connection failed", "errors" => sqlsrv_errors()]));
}

// Prepare the SQL query to retrieve data
$sql = "SELECT * FROM Table_Form_Translate WHERE Number_Caller = ?";
$params = array($numberCaller);

// Execute the query
$stmt = sqlsrv_query($conn, $sql, $params);

if ($stmt === false) {
    echo json_encode(["status" => "error", "message" => "Failed to retrieve data", "errors" => sqlsrv_errors()]);
    exit;
}

// Fetch the data
$dataArray = array();
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $dataArray[] = $row;
}

// Return the result
if (empty($dataArray)) {
    echo json_encode(["status" => "error", "message" => "No data found for the provided Number_Caller"]);
} else {
    echo json_encode(["status" => "success", "data" => $dataArray]);
}

// Free the statement and close the connection
sqlsrv_free_stmt($stmt);
sqlsrv_close($conn);
?>
