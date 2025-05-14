<?php
require 'vendor/autoload.php';

use OpenAI\Client;
use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Set headers for JSON response
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

try {
    // Get the raw POST data
    $json = file_get_contents('php://input');
    $data = json_decode($json);

    if (!$data || !isset($data->prompt)) {
        throw new Exception('No prompt provided');
    }

    // Initialize the OpenAI client
    $client = OpenAI::client($_ENV['OPENAI_API_KEY']);

    // Create the image
    $response = $client->images()->create([
        'prompt' => $data->prompt,
        'n' => 1,
        'size' => '1024x1024',
        'response_format' => 'url',
    ]);

    // Get the image URL
    $imageUrl = $response->data[0]->url;

    // Return the response
    echo json_encode([
        'success' => true,
        'url' => $imageUrl
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
