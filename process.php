<?php
require __DIR__ . '/vendor/autoload.php';

use OpenAI\Client;
use Dotenv\Dotenv;

header('Content-Type: application/json');

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Function to handle errors
function handleError($message) {
    echo json_encode([
        'success' => false,
        'message' => $message
    ]);
    exit;
}

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['prompt']) || !isset($input['outputType'])) {
        handleError('Missing required parameters');
    }

    $prompt = $input['prompt'];
    $outputType = $input['outputType'];

    // Initialize OpenAI client
    $client = OpenAI::client($_ENV['OPENAI_API_KEY']);

    // Prepare system message based on output type
    $systemPrompt = match($outputType) {
        'json' => 'You are a structured data generator that converts natural language into JSON format. Always return valid JSON.',
        'xml' => 'You are a structured data generator that converts natural language into XML format. Always return valid XML.',
        'yaml' => 'You are a structured data generator that converts natural language into YAML format. Always return valid YAML.',
        default => handleError('Invalid output type')
    };

    // Make the API call
    $response = $client->chat()->create([
        'model' => $_ENV['MODEL'] ?? 'gpt-3.5-turbo-16k',
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Convert this text into structured {$outputType} format: {$prompt}"]
        ],
        'temperature' => 0.7,
        'max_tokens' => 1000
    ]);

    // Get the response
    $result = $response->choices[0]->message->content;

    // Format the output for display
    $result = htmlspecialchars($result);

    // Return success response
    echo json_encode([
        'success' => true,
        'result' => $result
    ]);

} catch (Exception $e) {
    handleError('Error processing request: ' . $e->getMessage());
}
