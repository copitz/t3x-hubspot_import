<?php
/**
 * See class comment
 *
 * PHP version 7
 *
 * @category   Netresearch
 * @package    NrTemplate
 * @subpackage Error
 * @author     Christian Opitz <christian.opitz@netresearch.de>
 * @license    Netresearch http://www.netresearch.de/
 * @link       http://www.netresearch.de/
 */

namespace Netresearch\HubspotImport\Domain\Service;

use GeorgRinger\News\Domain\Model\Tag;
use Helhum\Typo3Console\Log\Writer\ConsoleWriter;
use NIMIUS\NewsBlog\Domain\Model\Author;
use NIMIUS\NewsBlog\Domain\Repository\AuthorRepository;
use TYPO3\CMS\Core\Log\LogLevel;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Extbase\Domain\Model\FileReference;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;

/**
 * Extended importer
 *
 * @category   Netresearch
 * @package    HubspotImport
 * @subpackage Domain
 * @author     Christian Opitz <christian.opitz@netresearch.de>
 * @license    Netresearch http://www.netresearch.de/
 * @link       http://www.netresearch.de/
 */
class NewsImportService extends \GeorgRinger\News\Domain\Service\NewsImportService {
    /**
     * @var \GeorgRinger\News\Domain\Repository\TagRepository
     * @inject
     */
    protected $tagRepository;

    /**
     * @var \TYPO3\CMS\Extbase\Object\ObjectManager
     * @inject
     */
    protected $objectManager;

    protected $tags = [];

    protected $authors = [];

    /**
     * @return \TYPO3\CMS\Core\Log\Logger
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * Add tags and author
     *
     * @param \GeorgRinger\News\Domain\Model\News $news
     * @param array $importItem
     * @param array $importItemOverwrite
     * @return \GeorgRinger\News\Domain\Model\News
     */
    protected function hydrateNewsRecord(
        \GeorgRinger\News\Domain\Model\News $news,
        array $importItem,
        array $importItemOverwrite
    )
    {
        if (!empty($importItemOverwrite)) {
            $importItem = array_merge($importItem, $importItemOverwrite);
        }

        $news = parent::hydrateNewsRecord($news, $importItem, []);
        $news->setPathSegment($importItem['path_segment']);

        $this->setTags($news, (array) $importItem['tags']);
        $this->setAuthor($news, $importItem['author_data']);

        return $news;
    }

    /**
     * Set author to news item
     *
     * @param \GeorgRinger\News\Domain\Model\News $news
     * @param string|array|null $authorData Author data array or username
     */
    protected function setAuthor(\GeorgRinger\News\Domain\Model\News $news, $authorData) {
        if (method_exists($news, 'setAuthorRecord')) {
            // EXT:news_blog installed in correct version
            $author = null;
            if ($authorData) {
                $username = is_array($authorData) ? $authorData['username'] : $authorData;
                /** @var Author $author */
                $author = $this->authors[$username];
                if (!$author) {
                    /** @var AuthorRepository $repo */
                    $repo = $this->objectManager->get(AuthorRepository::class);
                    $query = $repo->createQuery();
                    $author = $query->matching(
                        $query->equals('userName', $username)
                    )->execute()->getFirst();
                    if (!$author) {
                        $author = $this->objectManager->get(Author::class);
                        $author->setUserName($username);
                        $repo->add($author);
                    }
                    $this->authors[$username] = $author;
                }
                if (is_array($authorData)) {
                    $author->setAbstract($authorData['abstract']);
                    $author->setRealName($authorData['name']);
                    if ($authorData['avatar']) {
                        $avatar = $author->getAvatar();
                        if (!$avatar) {
                            /** @var FileReference $avatar */
                            $avatar = $this->objectManager->get(FileReference::class);
                            $author->setAvatar($avatar);
                        }
                        $reference = ResourceFactory::getInstance()->createFileReferenceObject([
                            'uid_local' => $authorData['avatar']
                        ]);
                        $avatar->setOriginalResource($reference);
                    }
                }
            }
            $news->setAuthorRecord($author);
        } else {
            $news->setAuthor($authorData['name']);
        }
    }

    /**
     * Set tags to news item
     *
     * @param \GeorgRinger\News\Domain\Model\News $news
     * @param array $tagList
     */
    protected function setTags(\GeorgRinger\News\Domain\Model\News $news, array $tagList) {
        /** @var ObjectStorage $tags */
        $tags = $this->objectManager->get(ObjectStorage::class);
        $pid = $news->getPid();

        if ($tagList) {
            if (!array_key_exists($pid, $this->tags)) {
                $this->tags[$pid] = [];
                $query = $this->tagRepository->createQuery();
                $query->getQuerySettings()
                    ->setStoragePageIds([$pid])
                    ->setRespectStoragePage(true);
                foreach ($query->execute() as $tag) {
                    /** @var Tag $tag */
                    $this->tags[$pid][$tag->getTitle()] = $tag;
                }
            }

            foreach ($tagList as $tagTitle) {
                if (!array_key_exists($tagTitle, $this->tags[$pid])) {
                    /** @var Tag $newTag */
                    $newTag = $this->objectManager->get(Tag::class);
                    $newTag->setPid($pid);
                    $newTag->setTitle($tagTitle);
                    $this->tagRepository->add($newTag);
                    $this->tags[$pid][$tagTitle] = $newTag;
                }
                $tags->attach($this->tags[$pid][$tagTitle]);
            }
        }

        $news->setTags($tags);
    }
}