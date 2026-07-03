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
        // Punto #1: acepta 'openai' (modo explícito OpenAI)
        if (!ProviderSettings::isRealMode()) {
            throw new RuntimeException("APP_MODE debe ser 'gemini' u 'openai' para usar APIs reales.");
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
        // Punto #1: acepta tanto 'gemini' (nuevo) como 'openai' (alias legacy) como modo real
        if (!ProviderSettings::isRealMode()) {
            throw new RuntimeException("APP_MODE debe ser 'gemini' u 'openai' para usar Gemini/Vertex AI.");
        }

        if (!ProviderSettings::allowRealApi()) {
            throw new RuntimeException('ALLOW_REAL_API debe ser true para consumir API real.');
        }

        // Se omite la validación de GEMINI_API_KEY ya que se utiliza Vertex AI + ADC Local.
    }

    public static function artworkProcessor(): ArtworkProcessorInterface
    {
        if (ProviderSettings::isRealMode()) {
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

    public static function mockupGenerator(): MockupGeneratorInterface
    {
        if (ProviderSettings::isRealMode()) {
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
