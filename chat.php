<?php
require 'vendor/autoload.php';

use OpenAI\Client;
use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Set headers for JSON response
header('Content-Type: application/json');

try {
    // Get the raw POST data
    $json = file_get_contents('php://input');
    $data = json_decode($json);

    if (!$data || !isset($data->message)) {
        throw new Exception('No message provided');
    }

    // Initialize the OpenAI client
    $client = OpenAI::client($_ENV['OPENAI_API_KEY']);

    // Create the chat completion
    $response = $client->chat()->create([
        'model' => 'gpt-4',
        'messages' => [
            ['role' => 'system', 'content' => 'You are a helpful and friendly assistant.'],
            ['role' => 'user', 'content' => $data->message]
        ],
        'temperature' => 0.7,
        'max_tokens' => 500
    ]);

    // Get the response content
    $botResponse = $response->choices[0]->message->content;

    // Send the response back to the client
    echo json_encode(['response' => $botResponse]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
