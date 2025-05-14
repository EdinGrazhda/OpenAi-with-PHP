<?php
require 'vendor/autoload.php';

use OpenAI\Client;
use Dotenv\Dotenv;

header('Content-Type: application/json');

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

try {
    // Get the raw POST data
    $json = file_get_contents('php://input');
    $data = json_decode($json);

    if (!$data || !isset($data->text)) {
        throw new Exception('No text provided');
    }

    // Create audio directory if it doesn't exist
    $audioDir = __DIR__ . '/audio';
    if (!file_exists($audioDir)) {
        mkdir($audioDir, 0777, true);
    }

    // Initialize the OpenAI client
    $client = OpenAI::client($_ENV['OPENAI_API_KEY']);

    // Set default parameters
    $voice = $data->voice ?? $_ENV['DEFAULT_VOICE'] ?? 'alloy';
    $model = $data->model ?? $_ENV['DEFAULT_MODEL'] ?? 'tts-1';
    $speed = $data->speed ?? floatval($_ENV['AUDIO_SPEED'] ?? 1.0);

    // Create speech
    $response = $client->audio()->speech([
        'model' => $model,
        'input' => $data->text,
        'voice' => $voice,
        'speed' => $speed,
    ]);

    // Generate a unique filename
    $filename = 'speech_' . uniqid() . '.mp3';
    $filepath = $audioDir . '/' . $filename;

    // Save the audio file
    file_put_contents($filepath, $response);

    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Audio generated successfully',
        'audio_url' => 'audio/' . $filename
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
