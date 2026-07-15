<?php
declare(strict_types=1);

interface VideoGenerationProvider
{
    public function name(): string;

    public function model(): string;

    /** @return array{jobId:string,status:string,response:array} */
    public function generateFromImage(array $payload): array;

    /** @return array{jobId:string,status:string,response:array} */
    public function generateFromFrames(array $payload): array;

    /** @return array{jobId:string,status:string,response:array} */
    public function extendVideo(array $payload): array;

    /** @return array{status:string,output?:array,error?:string,response:array} */
    public function getJobStatus(string $jobId): array;
}
