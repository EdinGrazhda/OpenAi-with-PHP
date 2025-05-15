<?php
require __DIR__ . '/vendor/autoload.php';

// Check required extensions
if (!extension_loaded('mbstring')) {
    die('The mbstring extension is required. Please enable it in your PHP configuration.');
}
if (!extension_loaded('iconv')) {
    die('The iconv extension is required. Please enable it in your PHP configuration.');
}

use OpenAI\Client;
use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

header('Content-Type: application/json');

function handleError($message) {
    echo json_encode([
        'success' => false,
        'message' => $message
    ]);
    exit;
}

// Validate file upload
if (!isset($_FILES['file'])) {
    handleError('No file was uploaded.');
}

$file = $_FILES['file'];
$fileName = $file['name'];
$fileTmpPath = $file['tmp_name'];
$fileSize = $file['size'];
$fileError = $file['error'];

// Check for upload errors
if ($fileError !== UPLOAD_ERR_OK) {
    handleError('Error uploading file.');
}

// Validate file size
$maxFileSize = $_ENV['MAX_FILE_SIZE'] ?? 10485760; // 10MB default
if ($fileSize > $maxFileSize) {
    handleError('File is too large. Maximum size is ' . ($maxFileSize / 1024 / 1024) . 'MB');
}

// Validate file type
$allowedTypes = explode(',', $_ENV['ALLOWED_FILE_TYPES'] ?? 'pdf,txt,doc,docx');
$fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
if (!in_array($fileExt, $allowedTypes)) {
    handleError('Invalid file type. Allowed types: ' . implode(', ', $allowedTypes));
}

// Create upload directory if it doesn't exist
$uploadDir = __DIR__ . '/uploads/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// Generate unique filename
$uniqueFilename = uniqid() . '_' . $fileName;
$uploadPath = $uploadDir . $uniqueFilename;

// Move uploaded file
if (!move_uploaded_file($fileTmpPath, $uploadPath)) {
    handleError('Failed to save the uploaded file.');
}

try {
    // Initialize OpenAI client
    $client = OpenAI::client($_ENV['OPENAI_API_KEY']);
    
    // Extract text content based on file type
    $fileContent = '';
    switch($fileExt) {
        case 'docx':
            $phpWord = \PhpOffice\PhpWord\IOFactory::load($uploadPath);
            $sections = $phpWord->getSections();
            foreach($sections as $section) {
                foreach($section->getElements() as $element) {
                    // Try to extract text from different element types
                    if (method_exists($element, 'getText')) {
                        $fileContent .= $element->getText() . "\n";
                    } elseif (method_exists($element, 'getElements')) {
                        $subElements = $element->getElements();
                        if (is_iterable($subElements)) {
                            foreach ($subElements as $subElement) {
                                if (method_exists($subElement, 'getText')) {
                                    $fileContent .= $subElement->getText() . "\n";
                                }
                            }
                        }
                    } elseif (property_exists($element, 'text')) {
                        $fileContent .= $element->text . "\n";
                    }
                }
            }
            break;
            
        case 'txt':
            $fileContent = file_get_contents($uploadPath);
            break;
            
        case 'pdf':
            if (extension_loaded('pdfparser')) {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($uploadPath);
                $fileContent = $pdf->getText();
            } else {
                handleError('PDF parsing requires the pdfparser extension.');
            }
            break;
            
        default:
            $fileContent = file_get_contents($uploadPath);
    }
    
    if (empty(trim($fileContent))) {
        handleError('No readable text content found in the file.');
    }
    
    // Detect encoding
    $encoding = mb_detect_encoding($fileContent, ['UTF-8', 'ISO-8859-1', 'ASCII', 'Windows-1252'], true);
    
    // Convert to UTF-8 if necessary
    if ($encoding && $encoding !== 'UTF-8') {
        $fileContent = mb_convert_encoding($fileContent, 'UTF-8', $encoding);
    } elseif (!$encoding) {
        // If encoding detection fails, try to force UTF-8
        $fileContent = mb_convert_encoding($fileContent, 'UTF-8', 'Windows-1252');
    }
    
    // Remove any invalid UTF-8 sequences
    $fileContent = iconv('UTF-8', 'UTF-8//IGNORE', $fileContent);
      // Get analysis type from request
    $analysisType = $_POST['analysisType'] ?? 'summary';
    
    // Calculate approximate token count (rough estimate: 4 characters per token)
    $estimatedTokens = strlen($fileContent) / 4;
    
    // Maximum tokens per chunk (3000 for content + room for system message and response)
    $maxTokensPerChunk = 3000;
    
    // Split content into smaller chunks for processing
    $chunks = [];
    
    if ($estimatedTokens > $maxTokensPerChunk) {
        // Split by paragraphs first
        $paragraphs = preg_split('/\n\s*\n/', $fileContent);
        $currentChunk = '';
        $currentTokens = 0;
        
        foreach ($paragraphs as $paragraph) {
            $paragraphTokens = strlen($paragraph) / 4;
            
            // If paragraph itself is too long, split it into sentences
            if ($paragraphTokens > $maxTokensPerChunk) {
                // Add current chunk if not empty
                if ($currentChunk !== '') {
                    $chunks[] = trim($currentChunk);
                    $currentChunk = '';
                    $currentTokens = 0;
                }
                
                // Split paragraph into sentences
                $sentences = preg_split('/(?<=[.!?])\s+/', $paragraph);
                $sentenceChunk = '';
                foreach ($sentences as $sentence) {
                    $sentenceTokens = strlen($sentence) / 4;
                    if ($sentenceTokens + $currentTokens > $maxTokensPerChunk) {
                        if ($sentenceChunk !== '') {
                            $chunks[] = trim($sentenceChunk);
                        }
                        $sentenceChunk = $sentence;
                        $currentTokens = $sentenceTokens;
                    } else {
                        $sentenceChunk .= ' ' . $sentence;
                        $currentTokens += $sentenceTokens;
                    }
                }
                if ($sentenceChunk !== '') {
                    $chunks[] = trim($sentenceChunk);
                }
            } else {
                // Handle regular paragraphs
                if ($currentTokens + $paragraphTokens > $maxTokensPerChunk) {
                    $chunks[] = trim($currentChunk);
                    $currentChunk = $paragraph;
                    $currentTokens = $paragraphTokens;
                } else {
                    $currentChunk .= "\n\n" . $paragraph;
                    $currentTokens += $paragraphTokens;
                }
            }
        }
        
        // Add the last chunk if not empty
        if (trim($currentChunk) !== '') {
            $chunks[] = trim($currentChunk);
        }
        
        // Process each chunk based on analysis type
        $results = [];
        foreach ($chunks as $index => $chunk) {
            $systemPrompt = match($analysisType) {
                'summary' => 'You are an AI assistant that provides concise summaries.',
                'analysis' => 'You are an AI assistant that provides detailed analysis, identifying key themes, arguments, and insights.',
                'keypoints' => 'You are an AI assistant that extracts and lists key points and important information.',
                default => 'You are an AI assistant that provides concise summaries.'
            };
            
            $userPrompt = match($analysisType) {
                'summary' => "Please provide a concise summary of this text segment (" . ($index + 1) . "/" . count($chunks) . "):\n\n",
                'analysis' => "Please analyze this text segment (" . ($index + 1) . "/" . count($chunks) . "), identifying key themes, arguments, and insights:\n\n",
                'keypoints' => "Please extract the key points and important information from this text segment (" . ($index + 1) . "/" . count($chunks) . "):\n\n",
                default => "Please summarize this text segment (" . ($index + 1) . "/" . count($chunks) . "):\n\n"
            };

            $response = $client->chat()->create([
                'model' => 'gpt-3.5-turbo-16k',
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt . $chunk]
                ],
                'temperature' => 0.7,
                'max_tokens' => 1000
            ]);
            
            $results[] = $response->choices[0]->message->content;
        }
        
        // Combine results
        $response = $client->chat()->create([
            'model' => 'gpt-3.5-turbo-16k',
            'messages' => [
                ['role' => 'system', 'content' => 'You are an AI assistant that combines multiple document analyses into a coherent final result.'],
                ['role' => 'user', 'content' => "Please combine these " . $analysisType . " sections into a coherent final result:\n\n" . implode("\n\n", $results)]
            ],
            'temperature' => 0.7,
            'max_tokens' => 1500
        ]);
    } else {
        // For shorter content, process as single chunk
        $systemPrompt = match($analysisType) {
            'summary' => 'You are an AI assistant that provides concise summaries.',
            'analysis' => 'You are an AI assistant that provides detailed analysis, identifying key themes, arguments, and insights.',
            'keypoints' => 'You are an AI assistant that extracts and lists key points and important information.',
            default => 'You are an AI assistant that provides concise summaries.'
        };
        
        $userPrompt = match($analysisType) {
            'summary' => "Please provide a concise summary of this text:\n\n",
            'analysis' => "Please analyze this text, identifying key themes, arguments, and insights:\n\n",
            'keypoints' => "Please extract the key points and important information from this text:\n\n",
            default => "Please summarize this text:\n\n"
        };

        $response = $client->chat()->create([
            'model' => 'gpt-3.5-turbo-16k',
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt . $fileContent]
            ],
            'temperature' => 0.7,
            'max_tokens' => 1500
        ]);
    }

    // Clean up - remove the uploaded file after analysis
    unlink($uploadPath);

    // Return success response with analysis
    echo json_encode([
        'success' => true,
        'message' => $response->choices[0]->message->content
    ]);

} catch (Exception $e) {
    // Clean up on error
    if (file_exists($uploadPath)) {
        unlink($uploadPath);
    }
    handleError('Error processing file: ' . $e->getMessage());
}
