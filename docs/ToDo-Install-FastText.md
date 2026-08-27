# FastText Installation Guide for Ubuntu

This document provides step-by-step instructions for installing FastText on an Ubuntu server to enable accurate language detection for the SRT translation validator.

## Prerequisites

- Ubuntu 18.04 or later
- Root or sudo access
- PHP 7.4 or 8.x installed
- Composer for PHP dependency management
- Basic knowledge of command-line operations

## Installation Steps

### 1. System Dependencies Installation

First, install the required system packages:

```bash
sudo apt update
sudo apt install -y build-essential cmake git wget
sudo apt install -y libgomp1
```

### 2. FastText Source Installation

Download and compile FastText from source:

```bash
# Clone the FastText repository
cd /tmp
git clone https://github.com/facebookresearch/fastText.git
cd fastText

# Compile FastText
mkdir build && cd build
cmake ..
make && make install

# Verify installation
fasttext
```

### 3. Download Pre-trained Language Identification Model

Download the pre-trained model for language identification:

```bash
# Create models directory
sudo mkdir -p /usr/local/share/fasttext
cd /usr/local/share/fasttext

# Download the language identification model
sudo wget https://dl.fbaipublicfiles.com/fasttext/supervised-models/lid.176.bin
```

### 4. PHP FastText Extension Installation

Option 1: Using PHP-CPP Extension (Recommended)

```bash
# Install PHP development packages
sudo apt install -y php-dev php-pear

# Install PHP-CPP
cd /tmp
git clone https://github.com/CopernicaMarketingSoftware/PHP-CPP.git
cd PHP-CPP
mkdir build && cd build
cmake ..
make && sudo make install

# Install FastText PHP extension
cd /tmp
git clone https://github.com/cubewood/fasttext-php.git
cd fasttext-php

# Compile and install
phpize
./configure --with-fasttext=/usr/local
make && sudo make install

# Enable extension
echo "extension=fasttext.so" | sudo tee -a /etc/php/8.2/cli/php.ini
```

Option 2: Using Python Bridge (Alternative)

```bash
# Install Python and FastText Python package
sudo apt install -y python3 python3-pip
pip3 install fasttext

# Install PHP-Python bridge
sudo apt install -y php8.2-cli python3.10-dev
pecl install python-bridge
echo "extension=python_bridge.so" | sudo tee -a /etc/php/8.2/cli/php.ini
```

### 5. Composer Package Installation

Update the project's composer.json to include a FastText PHP wrapper:

```bash
# Navigate to your project directory
cd /path/to/srt-translation-validator

# Install FastText PHP wrapper
composer require google/fasttext-php
```

Or if using a pure PHP implementation:

```bash
composer require romainneutron/fasttext-php
```

### 6. Verify Installation

Test the FastText installation:

```bash
# Create a test file
cat > test_fasttext.php << 'EOF'
<?php
require 'vendor/autoload.php';

try {
    $ft = new \FastText\FastText();
    
    // Test language detection
    $text = "Das ist ein deutscher Satz.";
    $result = $ft->predictLanguage($text);
    
    echo "Detected language: " . $result['language'] . PHP_EOL;
    echo "Confidence: " . $result['confidence'] . PHP_EOL;
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
}
EOF

# Run the test
php test_fasttext.php
```

### 7. Model Path Configuration

Set the model path in your PHP configuration or environment:

```bash
# Add to environment variables
echo 'export FASTTEXT_MODEL_PATH="/usr/local/share/fasttext/lid.176.bin"' | sudo tee -a /etc/environment

# Or set in PHP
echo 'fasttext.model_path = "/usr/local/share/fasttext/lid.176.bin"' | sudo tee -a /etc/php/8.2/cli/php.ini
```

## Integration with SRT Validator

### Replace Current Language Detection

Update `src/SrtTranslationValidator.php` to use FastText instead of the current library:

```php
// Replace this:
use LanguageDetection\Language;

// With this:
use FastText\FastText;
```

### Update Constructor

```php
public function __construct()
{
    $this->languageDetector = new FastText();
    $this->languageDetector->setModelPath('/usr/local/share/fasttext/lid.176.bin');
}
```

### Update Detection Method

```php
private function detectPartialTranslation(Subtitles $translation, string $expectedLanguage): array
{
    $defects = [];
    $blocks = $translation->getInternalFormat();

    // Use FastText for individual caption detection
    foreach ($blocks as $index => $block) {
        $text = implode(' ', $block['lines']);
        
        if (strlen(trim($text)) < 10) {
            continue;
        }
        
        if (preg_match('/^\[.*\]$/', trim($text))) {
            continue;
        }
        
        // FastText language detection
        $result = $this->languageDetector->predictLanguage($text);
        $detectedLang = substr($result['language'], 0, 2); // FastText returns ISO codes like "en", "de"
        $confidence = $result['confidence'];
        
        if ($detectedLang !== strtolower($expectedLanguage) && $confidence > 0.8) {
            $defects[] = [
                'type' => 'partial_translation',
                'message' => "Caption #" . ($index + 1) . " is in {$detectedLang} instead of {$expectedLanguage}",
                'caption_number' => $index + 1,
                'detected_language' => $detectedLang,
                'confidence' => $confidence,
                'text' => substr($text, 0, 100)
            ];
        }
    }
    
    return $defects;
}
```

## Testing

### Test FastText Performance

```bash
# Create a comprehensive test
cat > test_fasttext_performance.php << 'EOF'
<?php
require 'vendor/autoload.php';

$ft = new FastText\FastText();
$ft->setModelPath('/usr/local/share/fasttext/lid.176.bin');

// Test various languages
$testCases = [
    ['text' => 'Das ist ein deutscher Text.', 'expected' => 'de'],
    ['text' => 'This is English text.', 'expected' => 'en'],
    ['text' => 'Ceci est un texte français.', 'expected' => 'fr'],
    ['text' => 'Este es un texto en español.', 'expected' => 'es'],
    ['text' => 'Questo è un testo italiano.', 'expected' => 'it'],
];

$correct = 0;
foreach ($testCases as $test) {
    $result = $ft->predictLanguage($test['text']);
    $detected = substr($result['language'], 0, 2);
    
    echo "Text: " . $test['text'] . PHP_EOL;
    echo "Expected: " . $test['expected'] . ", Detected: " . $detected . PHP_EOL;
    echo "Confidence: " . $result['confidence'] . PHP_EOL;
    
    if ($detected === $test['expected']) {
        $correct++;
        echo "✓ CORRECT" . PHP_EOL;
    } else {
        echo "✗ INCORRECT" . PHP_EOL;
    }
    echo PHP_EOL;
}

echo "Accuracy: " . ($correct / count($testCases) * 100) . "%" . PHP_EOL;
EOF

php test_fasttext_performance.php
```

## Troubleshooting

### Issue: FastText command not found
```bash
# Add FastText to PATH
echo 'export PATH="/usr/local/bin:$PATH"' | sudo tee -a /etc/environment
source /etc/environment
```

### Issue: Model file not found
```bash
# Verify model file exists
ls -la /usr/local/share/fasttext/lid.176.bin

# If missing, re-download
sudo wget https://dl.fbaipublicfiles.com/fasttext/supervised-models/lid.176.bin -P /usr/local/share/fasttext/
```

### Issue: PHP extension not loading
```bash
# Check loaded extensions
php -m | grep fasttext

# Reinstall extension
sudo pecl uninstall fasttext
sudo pecl install fasttext
```

### Issue: Permission denied
```bash
# Set proper permissions
sudo chmod +x /usr/local/bin/fasttext
sudo chmod 644 /usr/local/share/fasttext/lid.176.bin
```

## Performance Considerations

- FastText is extremely fast (~10-100x faster than current library)
- Can handle thousands of detections per second
- Model file is ~125MB but cached in memory after first load
- Suitable for real-time subtitle validation

## Alternative: Using Python Service

If PHP extension installation is problematic, consider running FastText as a Python microservice:

```bash
# Create Python FastText service
cat > fasttext_service.py << 'EOF'
#!/usr/bin/env python3
from fasttext import load_model
from flask import Flask, request, jsonify

app = Flask(__name__)
model = load_model('/usr/local/share/fasttext/lid.176.bin')

@app.route('/detect', methods=['POST'])
def detect_language():
    text = request.json.get('text', '')
    result = model.predict(text, k=1)
    return jsonify({
        'language': result[0][0].replace('__label__', ''),
        'confidence': result[1][0]
    })

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000)
EOF

# Install dependencies
pip3 install fasttext flask gunicorn

# Run service
gunicorn -w 4 -b 0.0.0.0:5000 fasttext_service:app
```

## Next Steps

After installation:

1. Update the SRT validator to use FastText
2. Update test suite to work with new detection accuracy
3. Remove old `patrickschur/language-detection` dependency
4. Update documentation with new detection capabilities

## References

- [FastText GitHub Repository](https://github.com/facebookresearch/fastText)
- [FastText Language Identification Models](https://fasttext.cc/docs/en/language-identification.html)
- [176 Language Model Download](https://dl.fbaipublicfiles.com/fasttext/supervised-models/lid.176.bin)