<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display HTML errors

require __DIR__ . '/vendor/autoload.php';

use OpenAI\Client;
use Dotenv\Dotenv;

try {
    // Load environment variables
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['text'])) {
        throw new Exception('Missing text parameter');
    }

    $prompt = $input['text'];
    $outputType = $input['outputType'];

    // Initialize OpenAI client
    $client = OpenAI::client($_ENV['OPENAI_API_KEY']);

    // Prepare system message based on output type
    $systemPrompt = match($outputType) {
        'json' => 'You are a structured data generator that converts natural language into JSON format. Always return valid JSON.',
        'xml' => 'You are a structured data generator that converts natural language into XML format. Always return valid XML.',
        'yaml' => 'You are a structured data generator that converts natural language into YAML format. Always return valid YAML.',
        'html' => 'You are an HTML converter that transforms text into semantic HTML with modern styling. Include appropriate CSS for styling. Focus on creating clean, responsive, and visually appealing output.',
        default => throw new Exception('Invalid output type')
    };

    // Make the API call
    $response = $client->chat()->create([
        'model' => $_ENV['MODEL'] ?? 'gpt-3.5-turbo-16k',
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Convert this text into structured {$outputType} format: {$prompt}"]
        ],
        'temperature' => floatval($_ENV['DEFAULT_TEMPERATURE'] ?? 0.7),
        'max_tokens' => 2000
    ]);

    // Get the response
    $result = $response->choices[0]->message->content;

    // Special handling for HTML output
    if ($outputType === 'html') {
        // Don't escape HTML output as we want to render it
        $outputResult = $result;
    } else {
        // Escape other formats for display
        $outputResult = htmlspecialchars($result);
    }

    // Return success response
    echo json_encode([
        'success' => true,
        'result' => $outputResult,
        'format' => $outputType
    ]);

} catch (Exception $e) {
    // Return error as JSON
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
