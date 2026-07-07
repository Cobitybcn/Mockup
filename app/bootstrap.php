<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
require_once __DIR__ . '/Support/Database.php';
require_once __DIR__ . '/Support/Auth.php';
require_once __DIR__ . '/Support/ArtistProfile.php';
require_once __DIR__ . '/Support/Display.php';
require_once __DIR__ . '/Support/PromptSettings.php';
require_once __DIR__ . '/Support/RootArtworkCropper.php';
require_once __DIR__ . '/Support/ProviderSettings.php';
require_once __DIR__ . '/Support/Logger.php';
require_once __DIR__ . '/Support/ImageResizer.php';
require_once __DIR__ . '/Support/JsonStringNormalizer.php';
require_once __DIR__ . '/Support/ArtworkPhysicalIntegrityPolicy.php';
require_once __DIR__ . '/Support/WorldMotherCameraAuthorityPolicy.php';
require_once __DIR__ . '/Support/MockupVariationEligibility.php';
require_once __DIR__ . '/Support/MockupFavorites.php';
require_once __DIR__ . '/Support/AdminSceneEditor.php';
require_once __DIR__ . '/Contracts/ArtworkProcessorInterface.php';
require_once __DIR__ . '/Contracts/MockupGeneratorInterface.php';
require_once __DIR__ . '/Services/MockupContextWorldRegistry.php';
require_once __DIR__ . '/Services/MockupWorldVisualPromptEnhancer.php';
require_once __DIR__ . '/Services/WorldMotherLibrary.php';
require_once __DIR__ . '/Services/WorldMotherGenerator.php';
require_once __DIR__ . '/Services/CameraSlotStudio.php';
require_once __DIR__ . '/Services/ArtworkSheetService.php';
require_once __DIR__ . '/Services/ArtworkEmbeddingService.php';
require_once __DIR__ . '/Services/ArtworkGroupService.php';
require_once __DIR__ . '/Services/MockArtworkProcessor.php';
require_once __DIR__ . '/Services/MockMockupGenerator.php';
require_once __DIR__ . '/Services/MockupBatchQueue.php';
require_once __DIR__ . '/Services/SocialVideoService.php';
require_once __DIR__ . '/Services/VeoVideoClient.php';
require_once __DIR__ . '/Services/GeminiImageClient.php';
require_once __DIR__ . '/Services/GeminiArtworkProcessor.php';
require_once __DIR__ . '/Services/GeminiMockupGenerator.php';
require_once __DIR__ . '/Services/OpenAIArtworkProcessor.php';
require_once __DIR__ . '/Services/OpenAIMockupGenerator.php';
require_once __DIR__ . '/Services/MockupPromptApprovalService.php';
require_once __DIR__ . '/Services/AdminPromptComposerPreview.php';
require_once __DIR__ . '/Services/MockupCombinationEngine.php';
require_once __DIR__ . '/Services/ServiceFactory.php';
require_once __DIR__ . '/Support/DatabaseSessionHandler.php';
require_once __DIR__ . '/Services/CloudTasksService.php';
require_once __DIR__ . '/Services/StorageService.php';

if (Database::isMysql()) {
    $handler = new DatabaseSessionHandler();
    session_set_save_handler($handler, true);
}

