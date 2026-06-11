<?php
declare(strict_types=1);

class ServiceFactory
{
    public static function appMode(): string
    {
        return ProviderSettings::appMode();
    }

    public static function assertMockMode(): void
    {
        if (self::appMode() !== 'mock') {
            throw new RuntimeException('Este prototipo solo tiene implementaciones mock activas.');
        }
    }

    private static function assertOpenAIMode(): void
    {
        if (self::appMode() !== 'openai') {
            throw new RuntimeException('APP_MODE no esta configurado como openai.');
        }

        if (!ProviderSettings::allowRealApi()) {
            throw new RuntimeException('ALLOW_REAL_API debe ser true para consumir API real.');
        }

        if (ProviderSettings::openAIAPIKey() === '') {
            throw new RuntimeException('Falta OPENAI_API_KEY en administracion o variables de entorno.');
        }
    }

    private static function imageProvider(): string
    {
        return strtolower(ProviderSettings::imageProvider());
    }

    private static function assertGeminiProvider(): void
    {
        if (self::appMode() !== 'openai') {
            throw new RuntimeException('APP_MODE debe ser openai para usar Gemini.');
        }

        if (!ProviderSettings::allowRealApi()) {
            throw new RuntimeException('ALLOW_REAL_API debe ser true para consumir API real.');
        }
        
        // Se omite la validación de GEMINI_API_KEY ya que se utiliza Vertex AI + ADC Local.
    }

    public static function artworkProcessor(): ArtworkProcessorInterface
    {
        if (self::appMode() === 'openai') {
            if (self::imageProvider() === 'gemini') {
                self::assertGeminiProvider();
                return new GeminiArtworkProcessor();
            }

            self::assertOpenAIMode();
            return new OpenAIArtworkProcessor();
        }

        self::assertMockMode();
        return new MockArtworkProcessor();
    }

    public static function artworkAnalyzer(): ArtworkAnalyzerInterface
    {
        if (self::appMode() === 'openai') {
            if (self::imageProvider() === 'gemini') {
                self::assertGeminiProvider();
                return new GeminiArtworkAnalyzer(new MockContextSelector(new MockPromptBuilder()));
            } else {
                self::assertOpenAIMode();
            }

            return new OpenAIArtworkAnalyzer(new MockContextSelector(new MockPromptBuilder()));
        }

        self::assertMockMode();
        return new MockArtworkAnalyzer(new MockContextSelector(new MockPromptBuilder()));
    }

    public static function mockupGenerator(): MockupGeneratorInterface
    {
        if (self::appMode() === 'openai') {
            if (self::imageProvider() === 'gemini') {
                self::assertGeminiProvider();
                return new GeminiMockupGenerator();
            }

            self::assertOpenAIMode();
            return new OpenAIMockupGenerator();
        }

        self::assertMockMode();
        return new MockMockupGenerator();
    }
}
