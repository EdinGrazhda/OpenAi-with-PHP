# File Upload with OpenAI Analysis

This project provides a simple web interface for uploading files and analyzing their content using OpenAI's API.

## Features

- File upload with size and type validation
- Secure file handling
- OpenAI integration for content analysis
- Modern and responsive UI
- Real-time feedback

## Requirements

- PHP 8.1 or higher
- Composer
- OpenAI API key
- Web server (e.g., Apache, Nginx)

## Installation

1. Clone this repository
2. Install dependencies:
   ```bash
   composer install
   ```
3. Copy `.env.example` to `.env` and configure your OpenAI API key:
   ```bash
   cp .env.example .env
   ```
4. Update the `.env` file with your OpenAI API key
5. Make sure the `uploads` directory is writable by your web server

## Configuration

The following environment variables can be configured in `.env`:

- `OPENAI_API_KEY`: Your OpenAI API key
- `MAX_FILE_SIZE`: Maximum allowed file size in bytes (default: 10MB)
- `ALLOWED_FILE_TYPES`: Comma-separated list of allowed file extensions

## Usage

1. Access the application through your web browser
2. Select a file to upload using the file input
3. Click "Upload and Analyze"
4. Wait for the analysis results

## Security

- Files are validated for size and type
- Temporary files are immediately deleted after processing
- Sanitized file names
- Environment variables for sensitive data
