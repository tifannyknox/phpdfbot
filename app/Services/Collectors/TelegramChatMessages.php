<?php
declare(strict_types=1);

namespace App\Services\Collectors;

use App\Contracts\Collector\CollectorInterface;
use App\Contracts\Repositories\OpportunityRepository;
use App\Helpers\ExtractorHelper;
use App\Helpers\Helper;
use App\Helpers\SanitizerHelper;
use App\Models\Opportunity;
use App\Repositories\OpportunityRepositoryEloquent;
use App\Services\MadelineProtoService;
use App\Validators\CollectedOpportunityValidator;
use Carbon\Carbon;
use danog\MadelineProto\API;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Prettus\Validator\Contracts\ValidatorInterface;
use Prettus\Validator\Exceptions\ValidatorException;
use Spatie\Emoji\Emoji;

/**
 * Class TelegramChatMessages
 *
 * @property Collection $opportunities
 * @author Marcelo Rodovalho <rodovalhomf@gmail.com>
 */
class TelegramChatMessages implements CollectorInterface
{

    /** @var API|MadelineProtoService */
    private $madeline;

    /** @var Collection */
    private $opportunities;

    /** @var OpportunityRepository|OpportunityRepositoryEloquent */
    private $repository;

    /** @var CollectedOpportunityValidator */
    private $validator;

    /** @var callable */
    private $output;

    /**
     * TelegramChatMessages constructor.
     *
     * @param Collection                    $opportunities
     * @param MadelineProtoService          $madeline
     * @param OpportunityRepository         $repository
     * @param CollectedOpportunityValidator $validator
     * @param callable                      $output
     */
    public function __construct(
        Collection $opportunities,
        MadelineProtoService $madeline,
        OpportunityRepository $repository,
        CollectedOpportunityValidator $validator,
        callable $output
    ) {
        $this->madeline = $madeline;
        $this->opportunities = $opportunities;
        $this->repository = $repository;
        $this->validator = $validator;
        $this->output = $output;
    }

    /**
     * @return Collection
     */
    public function collectOpportunities(): Collection
    {
        $messages = $this->fetchMessages();
        foreach ($messages as $message) {
            $this->createOpportunity($message);
        }
        return $this->opportunities;
    }

    /**
     * @return iterable
     */
    public function fetchMessages(): iterable
    {
        $this->madeline->async(true);
        $madeline = $this->madeline;

        $groups = [
            '@vagastibr',
            '@vagastibh',
            '@vagasdetidf',
            '@vagastigo',
            '@vagasticbr',
            '@VagasTIRN',
            '@vagastec',
            '@vagastechsjc',
            '@sapbrasil',
            '@VagasParaTecnologia',
            '@vagastisapbrasil',
            '@vagastiportugal',
        ];

        $messages = $this->madeline->loop(function () use ($madeline, $groups) {
            yield $madeline->start();

            $history = [];
            $users = new Collection();
            $offsetDate = Carbon::now()->modify('-12 hours')->getTimestamp();
            foreach ($groups as $group) {
                try {
                    $result = yield $madeline->messages->getHistory([
                        'peer' => $group,
                        'offset_id' => 0,
                        'offset_date' => $offsetDate,
                        'add_offset' => -100,
                        'limit' => 100,
                        'max_id' => 0,
                        'min_id' => 0,
                    ]);
                    $users = $users->concat($result['users']);
                    $history[] = $result['messages'];
                    $messagesIds = Arr::pluck($result['messages'], 'id');
                    if (filled($messagesIds)) {
                        yield $madeline->channels->readHistory(['channel' => $group, 'max_id' => max($messagesIds),]);
                    }
                } catch (Exception $exception) {
                    Log::info('READ_HISTORY', [$group, $messagesIds]);
                    Log::error('READ_HISTORY_ERROR', [$exception->getMessage()]);
                }
            }

            $history = array_merge(...$history);

            $messages = [];
            foreach ($history as $message) {
                if (isset($message['media'])
                    && $message['media']['_'] === 'messageMediaPhoto') {
                    $files = yield $this->madeline->downloadToDir(
                        $message['media'],
                        Storage::path('attachments/telegram')
                    );
                    $message['files'] = $files;
                }
                if (array_key_exists('from_id', $message)) {
                    $user = $users->where('id', '=', $message['from_id'])->first();
                    if (array_key_exists('message', $message) && !$user['bot']) {
                        $message['user'] = $user;
                        $messages[] = $message;
                    }
                }
            }
            yield $madeline->echo('OK, done!');
            yield $madeline->stop();
            return $messages;
        });
        $this->madeline->stop();

        return $messages;
    }

    /**
     * @param $message
     *
     * @return mixed|void
     */
    public function createOpportunity($message)
    {
        $telegramUserId = $message['user']['id'];

        $original = $message['message'];

        $files = $this->extractFiles($message);

        $annotations = '';
        if (filled($files)) {
            $files = array_values($files);

            foreach ($files as $file) {
                if ($annotation = Helper::getImageAnnotation($file)) {
                    $annotations .= $annotation . "\n\n";
                }
            }
        }

        $message['message'] = $this->extractDescription($annotations . $message['message']);

        $title = $this->extractTitle($message['message']);

        $message = [
            Opportunity::TITLE => $title,
            Opportunity::DESCRIPTION => $message['message'],
            Opportunity::ORIGINAL => $original,
            Opportunity::FILES => $files,
            Opportunity::POSITION => '',
            Opportunity::COMPANY => '',
            Opportunity::LOCATION => $this->extractLocation($message['message']),
            Opportunity::TAGS => $this->extractTags($message['message']),
            Opportunity::SALARY => '',
            Opportunity::URL => $this->extractUrl($message),
            Opportunity::ORIGIN => $this->extractOrigin($message),
            Opportunity::EMAILS => $this->extractEmails($message),
        ];

        try {
            $this->validator
                ->with($message)
                ->passesOrFail(ValidatorInterface::RULE_CREATE);

            /** @var Collection $opportunities */
            $opportunities = $this->repository->scopeQuery(function ($query) {
                return $query->withTrashed();
            })->findWhere([
                Opportunity::TITLE => $message[Opportunity::TITLE],
                Opportunity::DESCRIPTION => $message[Opportunity::DESCRIPTION],
            ]);

            if ($opportunities->isEmpty()) {
                /** @var Opportunity $opportunity */
                $opportunity = $this->repository->make($message);

                $opportunity->update([
                    'telegram_user_id' => $telegramUserId
                ]);

                $this->opportunities->add($opportunity);
            }
        } catch (ValidatorException $exception) {
            $errors = $exception->getMessageBag()->all();
            $info = $this->output;
            $info(sprintf(
                "%s\n%s:\n%s %s\n",
                Emoji::downRightArrow(),
                $title,
                Emoji::crossMark(),
                implode("\n" . Emoji::crossMark() . ' ', $errors)
            ));
            Log::info('VALIDATION', [$errors, $message]);
        }
    }

    /**
     * @param $message
     *
     * @return string
     */
    public function extractTitle($message): string
    {
        return SanitizerHelper::sanitizeSubject(Str::limit(str_replace("\n", ' ', $message), 50));
    }

    /**
     * @param $message
     *
     * @return string
     */
    public function extractDescription($message): string
    {
        return SanitizerHelper::sanitizeBody($message);
    }

    /**
     * @param $message
     *
     * @return array
     */
    public function extractFiles($message): array
    {
        $files = [];
        if (array_key_exists('files', $message) && is_string($message['files'])) {
            $files[$message['files']] = Helper::cloudinaryUpload($message['files']);
            Storage::delete($message['files']);
        }
        return $files;
    }

    /**
     * @param $message
     *
     * @return string
     */
    public function extractOrigin($message): string
    {
        if (array_key_exists('to_id', $message) && isset($message['to_id'])) {
            unset($message['to_id']['_']);
            return json_encode($message['to_id']);
        }
        return '';
    }

    /**
     * @param $message
     *
     * @return string
     */
    public function extractLocation($message): string
    {
        return implode(' / ', ExtractorHelper::extractLocation($message));
    }

    /**
     * @param $message
     *
     * @return array
     */
    public function extractTags($message): array
    {
        return ExtractorHelper::extractTags($message);
    }

    /**
     * @param $message
     *
     * @return string
     */
    public function extractUrl($message): string
    {
        $urls = ExtractorHelper::extractUrls($message['message']);
        if (array_key_exists('user', $message)) {
            if (array_key_exists('username', $message['user'])) {
                $urls[] = sprintf(
                    'https://t.me/%s',
                    SanitizerHelper::escapeMarkdown($message['user']['username'])
                );
            } elseif (array_key_exists('first_name', $message['user'])) {
                $urls[] = sprintf(
                    '[%s](tg://user?id=%s)',
                    SanitizerHelper::escapeMarkdown($message['user']['first_name']),
                    $message['user']['id']
                );
            }
        }
        if (array_key_exists('media', $message)
            && $message['media']['_'] === 'messageMediaWebPage'
            && isset($message['media']['webpage']['url'])
        ) {
            $urls[] = $message['media']['webpage']['url'];
        }
        $urls = array_unique($urls);
        return implode(', ', $urls);
    }

    /**
     * @param $message
     *
     * @return string
     */
    public function extractEmails($message): string
    {
        $mails = ExtractorHelper::extractEmail($message['message']);
        return implode(', ', $mails);
    }
}
