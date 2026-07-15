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

    public static function generationProvider(string $requestedProvider = ''): string
    {
        $requestedProvider = strtolower(trim($requestedProvider));
        if (in_array($requestedProvider, ['gemini', 'openai'], true)) {
            return $requestedProvider;
        }

        return strtolower(ProviderSettings::imageProvider()) === 'openai' ? 'openai' : 'gemini';
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

    public static function artworkProcessor(string $requestedProvider = ''): ArtworkProcessorInterface
    {
        $generationProvider = self::generationProvider($requestedProvider);

        if (ProviderSettings::isRealMode()) {
            if ($generationProvider === 'gemini') {
                self::assertGeminiProvider();
                return new GeminiArtworkProcessor();
            }

            self::assertOpenAIMode();
            return new OpenAIArtworkProcessor(
                ProviderSettings::openAIAPIKey(),
                'gpt-image-2',
                'medium'
            );
        }

        self::assertMockMode();
        return new MockArtworkProcessor();
    }

    public static function mockupGenerator(string $requestedProvider = ''): MockupGeneratorInterface
    {
        $generationProvider = self::generationProvider($requestedProvider);

        if (ProviderSettings::isRealMode()) {
            if ($generationProvider === 'gemini') {
                self::assertGeminiProvider();
                return new GeminiMockupGenerator();
            }

            self::assertOpenAIMode();
            return new FidelityValidatingMockupGenerator(new OpenAIMockupGenerator(
                ProviderSettings::openAIAPIKey(),
                'gpt-image-2',
                '1024x1280',
                'medium'
            ));
        }

        self::assertMockMode();
        return new MockMockupGenerator();
    }
}
