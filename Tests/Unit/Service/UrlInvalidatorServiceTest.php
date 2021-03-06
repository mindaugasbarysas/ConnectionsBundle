<?php

/*
 * This file is part of the ONGR package.
 *
 * (c) NFQ Technologies UAB <info@nfq.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ONGR\ConnectionsBundle\Tests\Unit\Service;

use ONGR\ConnectionsBundle\Service\DocumentUrlCollectorInterface;
use ONGR\ConnectionsBundle\Service\UrlInvalidatorService;
use ONGR\ConnectionsBundle\Tests\Model\ProductModel;
use ONGR\ElasticsearchBundle\ORM\Manager;
use Symfony\Component\Routing\RouterInterface;

/**
 * Unit test for UrlInvalidatorService.
 */
class UrlInvalidatorServiceTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var Manager
     */
    protected $manager;

    /**
     * Setup mock for test.
     */
    public function setUp()
    {
        $this->manager = $this->getMockBuilder('ONGR\ElasticsearchBundle\ORM\Manager')
            ->disableOriginalConstructor()
            ->setMethods(['getRepository'])
            ->getMock();
        $this->manager->expects($this->any())->method('getRepository')->willReturnSelf();
    }

    /**
     * @param bool $invalidateSeo Flag passed to UrlInvalidatorService.
     *
     * @return UrlInvalidatorService
     */
    protected function getUrlInvalidatorServiceMock($invalidateSeo = false)
    {
        /** @var RouterInterface|\PHPUnit_Framework_MockObject_MockObject $router */
        $router = $this->getMock('Symfony\Component\Routing\RouterInterface');

        $service = new UrlInvalidatorService(
            $router,
            $this->manager,
            'http://ongr.dev',
            100,
            '',
            '',
            $invalidateSeo
        );

        return $service;
    }

    /**
     * Get fake url collector.
     *
     * @param array $documentUrls
     * @param array $documentParams
     * @param array $typeUrls
     *
     * @return DocumentUrlCollectorInterface
     */
    protected function getDocumentUrlCollectorMock(
        $documentUrls = [],
        $documentParams = [],
        $typeUrls = []
    ) {
        $collector = $this->getMock('ONGR\ConnectionsBundle\Service\DocumentUrlCollectorInterface');

        $collector->expects($this->any())->method('getDocumentUrls')->will($this->returnValue($documentUrls));
        $collector->expects($this->any())->method('getDocumentParameters')->will($this->returnValue($documentParams));
        $collector->expects($this->any())->method('getUrlsByType')->will($this->returnValue($typeUrls));

        return $collector;
    }

    /**
     * Test if documents are fetched correctly.
     */
    public function testDocumentUrls()
    {
        $service = $this->getUrlInvalidatorServiceMock();

        $doc = new ProductModel(null);
        $doc->url = [
            ['url' => 'test-url-1.html', 'key' => 't1'],
            ['url' => 'test-url-2.html', 'key' => 't2'],
        ];

        $service->loadUrlsFromDocument('content', $doc);
        $file = $service->createUrlsTempFile();

        $this->assertStringEqualsFile($file, '');

        unlink($file);
    }

    /**
     * Test document specific urls.
     */
    public function testDocumentSpecificUrls()
    {
        $service = $this->getUrlInvalidatorServiceMock();

        $doc = new ProductModel(null);
        $doc->url = [
            ['url' => 'test-url-1.html', 'key' => 't1'],
            ['url' => 'test-url-2.html', 'key' => 't2'],
        ];

        $collector = $this->getDocumentUrlCollectorMock(
            ['collector-generated-url-1.html']
        );

        $service->addUrlCollector($collector);
        $service->loadUrlsFromDocument('content', $doc);
        $file = $service->createUrlsTempFile();

        $this->assertStringEqualsFile(
            $file,
            implode(
                PHP_EOL,
                ['http://ongr.dev/collector-generated-url-1.html']
            )
        );

        unlink($file);
    }

    /**
     * Test document specific urls.
     */
    public function testTypeSpecificUrls()
    {
        $service = $this->getUrlInvalidatorServiceMock();

        $doc = new ProductModel(null);
        $doc->url = [
            ['url' => 'test-url-1.html', 'key' => 't1'],
            ['url' => 'test-url-2.html', 'key' => 't2'],
        ];

        $collector = $this->getDocumentUrlCollectorMock(
            [],
            [],
            [
                'collector-type-generated-url-1.html',
                'test-url-2.html',
            ]
        );

        $service->addUrlCollector($collector);
        $service->loadUrlsFromDocument('content', $doc);
        $service->loadUrlsByType('test');
        $file = $service->createUrlsTempFile();

        $this->assertStringEqualsFile(
            $file,
            implode(
                PHP_EOL,
                [
                    'http://ongr.dev/collector-type-generated-url-1.html',
                    'http://ongr.dev/test-url-2.html',
                ]
            )
        );

        unlink($file);
    }

    /**
     * Unit test to check whether default SEO url are invalidated if true falg is passed to UrlInvalidatorService.
     */
    public function testIvalidateDefaultUrls()
    {
        $service = $this->getUrlInvalidatorServiceMock(true);

        $doc = new ProductModel(null);
        $doc->url = [
            ['url' => 'test-url-1.html', 'key' => 't1'],
            ['url' => 'test-url-2.html', 'key' => 't2'],
        ];

        $service->loadUrlsFromDocument('content', $doc);
        $file = $service->createUrlsTempFile();

        $this->assertStringEqualsFile(
            $file,
            implode(
                PHP_EOL,
                [
                    'http://ongr.dev/test-url-1.html',
                    'http://ongr.dev/test-url-2.html',
                ]
            )
        );

        unlink($file);
    }
}
