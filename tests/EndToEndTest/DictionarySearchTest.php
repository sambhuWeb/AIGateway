<?php

namespace AIGateway\Tests\EndToEndTest;

use PHPUnit\Framework\TestCase;
use AIGateway\Client\GuzzleHTTPClient;
use AIGateway\Cache\FileCache;
use AIGateway\AI\OpenAI\OpenAIConnector;
use AIGateway\AI\Anthropic\AnthropicConnector;
use AIGateway\AI\DTO\Input\OpenAIRequestDTO;
use AIGateway\AI\DTO\Input\AnthropicRequestDTO;

/**
 * End-to-end tests for dictionary search functionality.
 *
 * Tests the AI's ability to return structured dictionary definitions.
 * Requires API keys to run.
 *
 * Run with:
 * OPENAI_API_KEY=your-key ANTHROPIC_API_KEY=your-key vendor/bin/phpunit tests/EndToEndTest/DictionarySearchTest.php
 */
class DictionarySearchTest extends TestCase
{
    private const DICTIONARY_PROMPT = 'Find the dictionary meaning of the word "beautiful" in Hindi. Return the response in EXACTLY the following JSON structure. Keep the structure identical and only fill in values where appropriate. Hindi meanings should be placed in the definition fields.

{
  "word": {
    "base": "beautiful",
    "variants": [
      {
        "language": "en",
        "region": "GB",
        "pronunciations": {
          "ipa": "/ˈbjuːtɪf(ə)l/"
        }
      },
      {
        "language": "en",
        "region": "US",
        "pronunciations": {
          "ipa": "/ˈbjuːtɪfəl/"
        }
      }
    ]
  },
  "definitions": [
    {
      "posTag": "ADJ",
      "posLabel": "adjective",
      "language": "hi",
      "senses": []
    },
    {
      "posTag": "NOUN",
      "posLabel": "noun",
      "language": "hi",
      "senses": []
    }
  ]
}';

    /** @var string */
    private $cacheDir;

    /** @var FileCache */
    private $cache;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/ai_gateway_dict_test_' . uniqid();
        $this->cache = new FileCache($this->cacheDir);
    }

    protected function tearDown(): void
    {
        if (isset($this->cacheDir) && is_dir($this->cacheDir)) {
            $files = glob($this->cacheDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->cacheDir);
        }
    }

    /**
     * @test
     * @group e2e
     * @group dictionary
     */
    public function it_returns_structured_dictionary_response_from_openai(): void
    {
        $apiKey = getenv('OPENAI_API_KEY');

        if (empty($apiKey)) {
            $this->markTestSkipped(
                'OpenAI API key not provided. Set OPENAI_API_KEY environment variable to run this test.'
            );
        }

        $httpClient = new GuzzleHTTPClient();
        $connector = new OpenAIConnector($httpClient, $this->cache);
        $connector->setApiKey($apiKey);

        $request = new OpenAIRequestDTO(
            'gpt-4',
            [['role' => 'user', 'content' => self::DICTIONARY_PROMPT]],
            0.0,
            2048,
            true,
            'You are a multilingual dictionary assistant. Always respond with valid JSON only, no additional text.'
        );

        $response = $connector->chat($request);
        $content = $response->getContent();

        // Extract JSON from response (in case there's markdown code blocks)
        $jsonContent = $this->extractJson($content);

        // Parse the JSON response
        $dictionary = json_decode($jsonContent, true);

        // Assert JSON parsing succeeded
        self::assertNotNull($dictionary, "Failed to parse JSON response. Raw content:\n" . $content);

        // Validate the structure
        $this->assertValidDictionaryStructure($dictionary);

        // Validate Hindi content
        $this->assertHindiDefinitionsPresent($dictionary);
    }

    /**
     * @test
     * @group e2e
     * @group dictionary
     */
    public function it_returns_structured_dictionary_response_from_anthropic(): void
    {
        $apiKey = getenv('ANTHROPIC_API_KEY');

        if (empty($apiKey)) {
            $this->markTestSkipped(
                'Anthropic API key not provided. Set ANTHROPIC_API_KEY environment variable to run this test.'
            );
        }

        $httpClient = new GuzzleHTTPClient();
        $connector = new AnthropicConnector($httpClient, $this->cache);
        $connector->setApiKey($apiKey);

        $request = new AnthropicRequestDTO(
            'claude-3-haiku-20240307',
            [['role' => 'user', 'content' => self::DICTIONARY_PROMPT]],
            0.0,
            2048,
            true,
            'You are a multilingual dictionary assistant. Always respond with valid JSON only, no additional text or markdown.'
        );

        $response = $connector->chat($request);
        $content = $response->getContent();

        // Extract JSON from response (in case there's markdown code blocks)
        $jsonContent = $this->extractJson($content);

        // Parse the JSON response
        $dictionary = json_decode($jsonContent, true);

        // Assert JSON parsing succeeded
        self::assertNotNull($dictionary, "Failed to parse JSON response. Raw content:\n" . $content);

        // Validate the structure
        $this->assertValidDictionaryStructure($dictionary);

        // Validate Hindi content
        $this->assertHindiDefinitionsPresent($dictionary);
    }

    /**
     * @test
     * @group e2e
     * @group dictionary
     */
    public function it_caches_dictionary_response(): void
    {
        $apiKey = getenv('OPENAI_API_KEY');

        if (empty($apiKey)) {
            $this->markTestSkipped(
                'OpenAI API key not provided. Set OPENAI_API_KEY environment variable to run this test.'
            );
        }

        $httpClient = new GuzzleHTTPClient();
        $connector = new OpenAIConnector($httpClient, $this->cache);
        $connector->setApiKey($apiKey);

        $request = new OpenAIRequestDTO(
            'gpt-3.5-turbo',
            [['role' => 'user', 'content' => self::DICTIONARY_PROMPT]],
            0.0,
            2048,
            false,
            'You are a multilingual dictionary assistant. Always respond with valid JSON only.'
        );

        // First call - should hit API
        $response1 = $connector->chat($request);
        self::assertFalse($response1->isFromCache());

        // Second call - should return from cache
        $response2 = $connector->chat($request);
        self::assertTrue($response2->isFromCache());
        self::assertEquals($response1->getContent(), $response2->getContent());
    }

    /**
     * Extract JSON from a response that might contain markdown code blocks.
     */
    private function extractJson(string $content): string
    {
        // Try to extract JSON from markdown code blocks
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $content, $matches)) {
            return trim($matches[1]);
        }

        // If no code block, try to find JSON object directly
        if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
            return trim($matches[0]);
        }

        return trim($content);
    }

    /**
     * Validate the dictionary response has the expected structure.
     */
    private function assertValidDictionaryStructure(array $dictionary): void
    {
        // Validate word structure
        self::assertArrayHasKey('word', $dictionary, 'Response should have "word" key');
        self::assertIsArray($dictionary['word'], 'Word should be an array');
        self::assertArrayHasKey('base', $dictionary['word'], 'Word should have "base" key');
        self::assertEqualsIgnoringCase('beautiful', $dictionary['word']['base'], 'Base word should be "beautiful"');

        // Validate variants (optional but expected)
        if (isset($dictionary['word']['variants'])) {
            self::assertIsArray($dictionary['word']['variants'], 'Variants should be an array');
            foreach ($dictionary['word']['variants'] as $variant) {
                if (is_array($variant)) {
                    self::assertArrayHasKey('language', $variant, 'Variant should have "language" key');
                }
            }
        }

        // Validate definitions
        self::assertArrayHasKey('definitions', $dictionary, 'Response should have "definitions" key');
        self::assertIsArray($dictionary['definitions'], 'Definitions should be an array');
        self::assertGreaterThanOrEqual(1, count($dictionary['definitions']), 'Should have at least 1 definition');

        // Check definition structure
        foreach ($dictionary['definitions'] as $index => $definition) {
            self::assertIsArray($definition, "Definition at index {$index} should be an array");
            self::assertArrayHasKey('posTag', $definition, "Definition at index {$index} should have 'posTag' key");
            self::assertArrayHasKey('language', $definition, "Definition at index {$index} should have 'language' key");
            self::assertArrayHasKey('senses', $definition, "Definition at index {$index} should have 'senses' key");
        }
    }

    /**
     * Validate that Hindi definitions are present in the response.
     */
    private function assertHindiDefinitionsPresent(array $dictionary): void
    {
        $hasHindiDefinition = false;
        $hasSenses = false;

        foreach ($dictionary['definitions'] as $definition) {
            // Check if this definition has a language key
            if (!isset($definition['language'])) {
                continue;
            }

            if ($definition['language'] === 'hi') {
                $hasHindiDefinition = true;

                if (!empty($definition['senses']) && is_array($definition['senses'])) {
                    $hasSenses = true;

                    // Validate sense structure
                    foreach ($definition['senses'] as $sense) {
                        if (!is_array($sense)) {
                            continue;
                        }
                        self::assertArrayHasKey('definition', $sense, 'Sense should have "definition" key');
                        self::assertNotEmpty($sense['definition'], 'Definition should not be empty');
                    }
                }
            }
        }

        self::assertTrue($hasHindiDefinition, 'Response should contain Hindi (hi) language definitions');
        self::assertTrue($hasSenses, 'Hindi definitions should have senses with meanings');
    }
}
