<?php

use App\Exceptions\GitLabApiException;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

it('classifies 429 as transient', function () {
    $exception = new GitLabApiException(
        message: 'Rate limited',
        statusCode: 429,
        responseBody: '{"error":"rate_limit_exceeded"}',
        context: 'createMRNote',
    );

    expect($exception->isTransient())->toBeTrue();
    expect($exception->shouldRetry())->toBeTrue();
    expect($exception->classification())->toBe('transient');
});

it('classifies 500 as transient', function () {
    $exception = new GitLabApiException(
        message: 'Server error',
        statusCode: 500,
        responseBody: 'Internal Server Error',
        context: 'getMergeRequest',
    );

    expect($exception->isTransient())->toBeTrue();
    expect($exception->shouldRetry())->toBeTrue();
});

it('classifies 503 as transient', function () {
    $exception = new GitLabApiException(
        message: 'Service unavailable',
        statusCode: 503,
        responseBody: 'Service Unavailable',
        context: 'createMRDiscussion',
    );

    expect($exception->isTransient())->toBeTrue();
    expect($exception->shouldRetry())->toBeTrue();
});

it('classifies 529 as transient', function () {
    $exception = new GitLabApiException(
        message: 'Overloaded',
        statusCode: 529,
        responseBody: 'Site is overloaded',
        context: 'addMRLabels',
    );

    expect($exception->isTransient())->toBeTrue();
    expect($exception->shouldRetry())->toBeTrue();
    expect($exception->classification())->toBe('transient');
});

it('classifies 400 as invalid request', function () {
    $exception = new GitLabApiException(
        message: 'Bad request',
        statusCode: 400,
        responseBody: '{"error":"invalid_parameter"}',
        context: 'createMRNote',
    );

    expect($exception->isInvalidRequest())->toBeTrue();
    expect($exception->shouldRetry())->toBeFalse();
    expect($exception->classification())->toBe('invalid_request');
});

it('classifies 401 as authentication error', function () {
    $exception = new GitLabApiException(
        message: 'Unauthorized',
        statusCode: 401,
        responseBody: '{"message":"401 Unauthorized"}',
        context: 'triggerPipeline',
    );

    expect($exception->isAuthenticationError())->toBeTrue();
    expect($exception->shouldRetry())->toBeFalse();
    expect($exception->classification())->toBe('authentication');
});

it('classifies other status codes as unknown', function () {
    $exception = new GitLabApiException(
        message: 'Forbidden',
        statusCode: 403,
        responseBody: '{"message":"403 Forbidden"}',
        context: 'setCommitStatus',
    );

    expect($exception->isTransient())->toBeFalse();
    expect($exception->isInvalidRequest())->toBeFalse();
    expect($exception->isAuthenticationError())->toBeFalse();
    expect($exception->shouldRetry())->toBeFalse();
    expect($exception->classification())->toBe('unknown');
});

it('creates from RequestException with correct fields', function () {
    $psr7Response = new Psr7Response(429, [], '{"error":"rate_limit"}');
    $response = new Response($psr7Response);
    $requestException = new RequestException($response);

    $gitlab = GitLabApiException::fromRequestException($requestException, 'testContext');

    expect($gitlab->statusCode)->toBe(429);
    expect($gitlab->context)->toBe('testContext');
    expect($gitlab->responseBody)->toBe('{"error":"rate_limit"}');
    expect($gitlab->isTransient())->toBeTrue();
    expect($gitlab->getPrevious())->toBe($requestException);
});

it('preserves status code as exception code', function () {
    $exception = new GitLabApiException(
        message: 'Server error',
        statusCode: 503,
        responseBody: '',
        context: 'test',
    );

    expect($exception->getCode())->toBe(503);
});
