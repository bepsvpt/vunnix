<?php

namespace App\Modules\Chat\Application\Providers;

use App\Agents\Tools\BrowseRepoTree;
use App\Agents\Tools\DispatchAction;
use App\Agents\Tools\ListIssues;
use App\Agents\Tools\ListMergeRequests;
use App\Agents\Tools\ReadFile;
use App\Agents\Tools\ReadIssue;
use App\Agents\Tools\ReadMergeRequest;
use App\Agents\Tools\ReadMRDiff;
use App\Agents\Tools\ResolveGitLabUser;
use App\Agents\Tools\SearchCode;
use App\Modules\Chat\Application\Contracts\ChatToolsetProvider;

class DefaultChatToolsetProvider implements ChatToolsetProvider
{
    public function tools(): iterable
    {
        return [
            app(BrowseRepoTree::class),
            app(ReadFile::class),
            app(SearchCode::class),
            app(ListIssues::class),
            app(ReadIssue::class),
            app(ListMergeRequests::class),
            app(ReadMergeRequest::class),
            app(ReadMRDiff::class),
            app(DispatchAction::class),
            app(ResolveGitLabUser::class),
        ];
    }
}
