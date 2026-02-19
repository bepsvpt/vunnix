<?php

namespace App\Modules\GitLabIntegration\Application\Contracts;

interface GitLabPort extends GitLabIssuePort, GitLabMergeRequestPort, GitLabPipelinePort, GitLabRepoPort {}
