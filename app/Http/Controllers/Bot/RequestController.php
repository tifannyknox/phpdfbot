<?php

namespace App\Http\Controllers\Bot;

use App\Contracts\Interfaces\OpportunityInterface;
use App\Contracts\Opportunity;
use App\Http\Controllers\Controller;
use Dacastro4\LaravelGmail\Services\Message\Attachment;
use Dacastro4\LaravelGmail\Services\Message\Mail;
use LaravelGmail;
use Telegram\Bot\BotsManager;

class RequestController extends Controller
{
    /**
     * @var \Telegram\Bot\Api
     */
    private $telegram;

    /**
     * RequestController constructor.
     * @param BotsManager $botsManager
     */
    public function __construct(BotsManager $botsManager)
    {
        $this->telegram = $botsManager->bot();
    }

    /**
     * @return string
     */
    public function process(): string
    {
        $messages = $this->getMessages();
//dump($messages->first()->getBody());
//$attachment = base64_decode($messages->first()->getAttachments(true)[0]);
//echo "<img src='data:image/jpeg;base64,$attachment'>";
        /** @var Attachment $attach */
//        $attach = $messages->first()->getAttachments()[0];
//        $myme = $attach->getMimeType();
//        $attachment = base64_encode($attach->getDecodedBody($attach->getData()));
//        echo "<img src='data:$myme;base64,$attachment'>";
        /** @var Mail $message */
        foreach ($messages as $message) {
            $body = $this->sanitizeBody($message->getHtmlBody());
            dump($body);
            /** TODO: Format message here */
            $opportunity = new Opportunity();
            $opportunity->setTitle($message->getSubject())
                ->setDescription($body);
            $this->sendOpportunityToChannel($opportunity);
        }
        return 'ok';
    }

    private function getMessages(): \Illuminate\Support\Collection
    {
        /** @var \Dacastro4\LaravelGmail\Services\Message $messageService */
        $messageService = LaravelGmail::message();
        $threads = $messageService->service->users_messages->listUsersMessages('me', [
            'q' => '(list:nvagas@googlegroups.com OR list:leonardoti@googlegroups.com ' .
                'OR list:clubinfobsb@googlegroups.com OR to:nvagas@googlegroups.com OR to:vagas@noreply.github.com ' .
                'OR to:clubinfobsb@googlegroups.com OR to:leonardoti@googlegroups.com) is:unread',
            'maxResults' => 5
        ]);

        $messages = [];
        $allMessages = $threads->getMessages();
        foreach ($allMessages as $message) {
            $messages[] = new Mail($message, true);
        }
        return collect($messages);
    }

    private function sendOpportunityToChannel(OpportunityInterface $opportunity): void
    {
        $this->telegram->sendMessage([
            'parse_mode' => 'Markdown',
            'chat_id' => env('TELEGRAM_OWNER_ID'),
            'text' => implode("\r\n", [
                $opportunity->getTitle(),
                $opportunity->getDescription()
            ])
        ]);
    }

    private function sanitizeBody(string $message): string
    {
        if ($message) {
            $delimiters = [
                'You are receiving this because you are subscribed to this thread',
                'Você recebeu esta mensagem porque está inscrito para o Google',
                'Você está recebendo esta mensagem porque',
                'Esta mensagem pode conter informa',
                'Você recebeu esta mensagem porque',
                'Antes de imprimir',
                'This message contains',
                'NVagas Conectando',
                'cid:image',
                'Atenciosamente',
                'Att.',
                'Att,',
                'AVISO DE CONFIDENCIALIDADE',
                'Receba vagas no whatsapp',
            ];

            $messageArray = explode($delimiters[0], str_replace($delimiters, $delimiters[0], $message));

            $message = $messageArray[0];
            dump($message);

            $message = $this->removeTagsAttributes($message);
            $message = $this->removeEptyTagsRecursive($message);
            $message = $this->closeOpenTags($message);

            dump($message);

            $message = str_replace(['*', '_', '`'], '', $message);
            $message = str_ireplace(['<strong>', '<b>', '</b>', '</strong>'], '*', $message);
            $message = str_ireplace(['<i>', '</i>', '<em>', '</em>'], '_', $message);
            $message = str_ireplace([
                '<h1>', '</h1>', '<h2>', '</h2>', '<h3>', '</h3>', '<h4>', '</h4>', '<h5>', '</h5>', '<h6>', '</h6>'
            ], '`', $message);
            $message = preg_replace('/<br(\s+)?\/?>/i', "\n", $message);
            $message = preg_replace("/<p[^>]*?>/", '', $message);
            $message = str_replace("</p>", "\r\n", $message);
            $message = strip_tags($message);

            dump($message);
            $message = str_replace(['**', '__', '``'], '', $message);
            $message = preg_replace("/[\r\n]+/", "\n", $message);
        }
        return trim($message);
    }

    private function removeTagsAttributes($message)
    {
        return preg_replace("/<([a-z][a-z0-9]*)[^>]*?(\/?)>/i", '<$1$2>', $message);
    }

    private function closeOpenTags($message)
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML(mb_convert_encoding($message, 'HTML-ENTITIES', 'UTF-8'));
        $mock = new \DOMDocument;
        $body = $dom->getElementsByTagName('body')->item(0);
        foreach ($body->childNodes as $child) {
            $mock->appendChild($mock->importNode($child, true));
        }
        return trim((html_entity_decode($mock->saveHTML())));
    }

    /**
     * remove_empty_tags_recursive ()
     * Remove the nested HTML empty tags from the string.
     *
     * @author    Junaid Atari <mj.atari@gmail.com>
     * @version    1.0
     * @param    string $str String to remove tags.
     * @param    string $repto Replace empty string with.
     * @return    string    Cleaned string.
     */
    private function removeEptyTagsRecursive($str, $repto = NULL)
    {
        //** Return if string not given or empty.
        if (!is_string($str)
            || trim($str) == '')
            return $str;

        //** Recursive empty HTML tags.
        return preg_replace(

        //** Pattern written by Junaid Atari.
            '/<([^<\/>]*)>([\s]*?|(?R))<\/\1>/imsU',

            //** Replace with nothing if string empty.
            !is_string($repto) ? '' : $repto,

            //** Source string
            $str
        );
    }
}
