<?php
declare(strict_types=1);

/**
 * FASE 3 - Tests de no-regresion para las zonas protegidas de la auditoria
 * de prompts (docs/AUDITORIA_PROMPTS_MOCKUPS_20260701.md).
 *
 * No genera mockups, no llama a Gemini, no escribe en tablas de produccion.
 * Cubre: generacion de obra raiz (prompts + normalizacion pura), obra raiz
 * subida por el usuario (caracterizacion de codigo).
 *
 * Uso: php tests/run_regression_tests.php
 * Exit code 0 si todo pasa, 1 si alguna asercion falla.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/TestHarness.php';
require_once __DIR__ . '/regression/root_artwork_test.php';
require_once __DIR__ . '/regression/seo_filename_test.php';
require_once __DIR__ . '/regression/public_slug_test.php';
require_once __DIR__ . '/regression/uploaded_root_test.php';
require_once __DIR__ . '/regression/public_pages_test.php';
require_once __DIR__ . '/regression/world_mother_library_admin_test.php';
require_once __DIR__ . '/regression/scene_ranking_service_test.php';
require_once __DIR__ . '/regression/world_mother_multi_reference_test.php';
require_once __DIR__ . '/regression/generation_provider_isolation_test.php';
require_once __DIR__ . '/regression/external_mockup_upload_test.php';
require_once __DIR__ . '/regression/feature_access_test.php';
require_once __DIR__ . '/regression/language_policy_test.php';
require_once __DIR__ . '/regression/artist_onboarding_test.php';
require_once __DIR__ . '/regression/artist_site_metrics_test.php';
require_once __DIR__ . '/regression/website_board_grouping_test.php';
require_once __DIR__ . '/regression/schema_migration_governance_test.php';
require_once __DIR__ . '/regression/bilingual_editorial_service_test.php';
require_once __DIR__ . '/regression/editorial_core_contract_test.php';
require_once __DIR__ . '/regression/publication_section_test.php';
require_once __DIR__ . '/regression/publication_product_service_test.php';
require_once __DIR__ . '/regression/publication_distribution_service_test.php';
require_once __DIR__ . '/regression/zip_package_test.php';
require_once __DIR__ . '/regression/studio_note_bilingual_test.php';
require_once __DIR__ . '/regression/studio_note_change_classifier_test.php';
require_once __DIR__ . '/regression/studio_note_workspace_test.php';
require_once __DIR__ . '/regression/studio_note_markdown_import_test.php';
require_once __DIR__ . '/regression/editorial_integrity_policy_test.php';
require_once __DIR__ . '/regression/mockup_social_content_test.php';
require_once __DIR__ . '/regression/social_board_destination_settings_test.php';
require_once __DIR__ . '/regression/mockup_favorites_order_test.php';
require_once __DIR__ . '/regression/mockup_editorial_batch_test.php';
require_once __DIR__ . '/regression/artwork_editorial_package_test.php';
require_once __DIR__ . '/regression/series_keyword_research_test.php';
require_once __DIR__ . '/regression/retired_reference_lab_test.php';
require_once __DIR__ . '/regression/ui_preview_test.php';
require_once __DIR__ . '/regression/security_hardening_test.php';
require_once __DIR__ . '/regression/artist_domain_verification_test.php';
require_once __DIR__ . '/regression/deployment_pipeline_test.php';
require_once __DIR__ . '/regression/public_artist_showcase_test.php';
require_once __DIR__ . '/regression/video_media_range_test.php';

run_root_artwork_regression_tests();
run_seo_filename_regression_tests();
run_public_slug_regression_tests();
run_uploaded_root_regression_tests();
run_public_pages_regression_tests();
run_world_mother_library_admin_tests();
run_scene_ranking_service_regression_tests();
run_world_mother_multi_reference_regression_tests();
run_generation_provider_isolation_tests();
run_external_mockup_upload_regression_tests();
run_feature_access_regression_tests();
run_language_policy_tests();
run_artist_onboarding_tests();
run_artist_site_metrics_tests();
run_website_board_grouping_regression_tests();
run_schema_migration_governance_tests();
run_bilingual_editorial_service_tests();
run_artwork_single_generator_tests();
run_mockup_ficha_routing_tests();
run_editorial_core_contract_tests();
run_publication_section_tests();
run_publication_product_service_tests();
run_publication_distribution_service_tests();
run_zip_package_tests();
run_studio_note_bilingual_tests();
run_studio_note_change_classifier_tests();
run_studio_note_workspace_tests();
run_studio_note_markdown_import_tests();
run_editorial_integrity_policy_tests();
run_mockup_social_content_tests();
run_social_board_destination_settings_tests();
run_mockup_favorites_order_regression_tests();
run_mockup_editorial_batch_tests();
run_artwork_editorial_package_tests();
run_series_keyword_research_tests();
run_retired_reference_lab_tests();
run_ui_preview_regression_tests();
run_security_hardening_regression_tests();
run_artist_domain_verification_tests();
run_deployment_pipeline_regression_tests();
run_public_artist_showcase_tests();
run_video_media_range_regression_tests();

exit(TestHarness::summary());
