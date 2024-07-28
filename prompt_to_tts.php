<?php
// Set headers to allow cross-origin requests (if needed)
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// Record the start time
$start_time = microtime(true);

// Get the input data from URL-encoded form
$assistant_id = $_POST['assistant_id'];
$thread_id = $_POST['thread_id'] ?? null;
$prompt = $_POST['prompt'];
$api_key = $_POST['api_key'];
$instructions = $_POST['instructions'] ?? 'You are a helpful assistant.';
$voice = $_POST['voice'] ?? null; // New voice parameter

if (empty($assistant_id) || empty($prompt) || empty($api_key)) {
    http_response_code(400);
    echo json_encode(["error" => "Missing required fields"]);
    exit;
}

$openaiApiUrl = 'https://api.openai.com/v1';

// Function to poll for run completion with reduced interval
function waitForRunCompletion($thread_id, $run_id, $api_key) {
    $polling_url = "https://api.openai.com/v1/threads/$thread_id/runs/$run_id";
    while (true) {
        $ch = curl_init($polling_url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $api_key",
            "Content-Type: application/json",
            "OpenAI-Beta: assistants=v2"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $poll_response = curl_exec($ch);
        $poll_http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($poll_http_status == 200) {
            $poll_responseData = json_decode($poll_response, true);
            if (isset($poll_responseData["status"]) && $poll_responseData["status"] == "completed") {
                return $poll_responseData;
            }
        }
        usleep(500000); // Wait 0.5 second before the next attempt (500000 microseconds)
    }
}

function generateTTS($api_key, $text, $voice, $filename) {
    $url = 'https://api.openai.com/v1/audio/speech';

    $data = [
        'model' => 'tts-1',
        'input' => $text,
        'voice' => $voice
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key,
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $output_directory = __DIR__ . '/audio_output';
    $output_file = $output_directory . '/' . $filename;

    if (!file_exists($output_directory)) {
        mkdir($output_directory, 0777, true);
    }

    $fp = fopen($output_file, 'wb');
    curl_setopt($ch, CURLOPT_FILE, $fp);

    curl_exec($ch);

    if (curl_errno($ch)) {
        fclose($fp);
        curl_close($ch);
        throw new Exception('Error: ' . curl_error($ch));
    }

    fclose($fp);
    curl_close($ch);

    return 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/audio_output/' . $filename;
}

try {
    if (empty($thread_id)) {
        // Create a new thread
        $thread_url = "$openaiApiUrl/threads";
        $ch = curl_init($thread_url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $api_key",
            "Content-Type: application/json",
            "OpenAI-Beta: assistants=v2"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([]));
        $thread_response = curl_exec($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_status != 200) {
            http_response_code($http_status);
            echo json_encode([
                "error" => "Failed to create thread",
                "details" => $thread_response
            ]);
            exit;
        }

        $threadData = json_decode($thread_response, true);
        $thread_id = $threadData["id"];
    }

    // Record the time before sending the prompt
    $prompt_start_time = microtime(true);

    // Send prompt to the thread
    $message_url = "$openaiApiUrl/threads/$thread_id/messages";
    $message_data = [
        "role" => "user",
        "content" => $prompt
    ];
    $ch = curl_init($message_url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $api_key",
        "Content-Type: application/json",
        "OpenAI-Beta: assistants=v2"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message_data));
    $message_response = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_status != 200) {
        http_response_code($http_status);
        echo json_encode([
            "error" => "Failed to send message to thread",
            "details" => $message_response
        ]);
        exit;
    }

    // Generate a response from the assistant
    $run_url = "$openaiApiUrl/threads/$thread_id/runs";
    $run_data = [
        "assistant_id" => $assistant_id,
        "instructions" => $instructions
    ];
    $ch = curl_init($run_url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $api_key",
        "Content-Type: application/json",
        "OpenAI-Beta: assistants=v2"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($run_data));
    $run_response = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_status != 200) {
        http_response_code($http_status);
        echo json_encode([
            "error" => "Failed to generate response",
            "details" => $run_response
        ]);
        exit;
    }

    $runData = json_decode($run_response, true);
    $run_id = $runData["id"];

    // Poll for run completion
    $completedRun = waitForRunCompletion($thread_id, $run_id, $api_key);

    // Record the time after receiving the prompt response
    $prompt_end_time = microtime(true);

    // Fetch messages from the thread
    $messages_url = "$openaiApiUrl/threads/$thread_id/messages";
    $ch = curl_init($messages_url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $api_key",
        "Content-Type: application/json",
        "OpenAI-Beta: assistants=v2"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $messages_response = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_status != 200) {
        http_response_code($http_status);
        echo json_encode([
            "error" => "Failed to retrieve messages from the thread",
            "details" => $messages_response
        ]);
        exit;
    }

    $messagesData = json_decode($messages_response, true);
    $assistantMessages = array_filter($messagesData['data'], function($message) {
        return $message['role'] == 'assistant';
    });
    $lastAssistantMessage = array_shift($assistantMessages);
    $responseText = '';
    if (isset($lastAssistantMessage['content'])) {
        foreach ($lastAssistantMessage['content'] as $content_part) {
            if ($content_part['type'] == 'text') {
                $responseText .= $content_part['text']['value'];
            }
        }
    } else {
        $responseText = "No response from assistant.";
    }

    // Generate TTS if voice is provided
    $voice_link = null;
    $tts_start_time = null;
    $tts_end_time = null;
    if ($voice && !empty($responseText)) {
        $tts_start_time = microtime(true);
        $message_id = $lastAssistantMessage['id'];
        $filename = $message_id . '.mp3';
        $voice_link = generateTTS($api_key, $responseText, $voice, $filename);
        $tts_end_time = microtime(true);
    }

    // Calculate durations
    $duration = isset($completedRun['completed_at']) && isset($completedRun['started_at']) ? $completedRun['completed_at'] - $completedRun['started_at'] : null;
    $prompt_duration = $prompt_end_time - $prompt_start_time;
    $tts_duration = $tts_end_time && $tts_start_time ? $tts_end_time - $tts_start_time : 0;

    // Record the end time and calculate the complete duration
    $end_time = microtime(true);
    $complete_duration = $end_time - $start_time;

    echo json_encode([
        "assistant_id" => $assistant_id,
        "thread_id" => $thread_id,
        "response" => $responseText,
        "prompt_token" => $completedRun["usage"]["prompt_tokens"] ?? 0,
        "response_token" => $completedRun["usage"]["completion_tokens"] ?? 0,
        "total_token" => $completedRun["usage"]["total_tokens"] ?? 0,
        "duration" => $duration,
        "prompt_duration" => $prompt_duration,
        "tts_duration" => $tts_duration,
        "complete_duration" => $complete_duration,
        "voice_link" => $voice_link
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "error" => "An error occurred",
        "details" => $e->getMessage()
    ]);
}
?>
