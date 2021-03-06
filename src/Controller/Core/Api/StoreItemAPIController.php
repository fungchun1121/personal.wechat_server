<?php
/**
 * Created by PhpStorm.
 * User: ypoon
 * Date: 20/11/2018
 * Time: 5:19 PM
 */

namespace App\Controller\Core\Api;

use App\Entity\Core\AbstractModule;
use App\Entity\Core\AbstractStoreFront;
use App\Entity\Core\AbstractStoreItem;
use App\Entity\Core\Housing\HousingItem;
use App\Entity\Core\SecondHand\SecondHandItem;
use App\Entity\Core\SecondHand\SecondHandStoreFront;
use App\Entity\Core\StickyTicket;
use Intervention\Image\Constraint;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Validator\Constraints as Assert;
use App\Entity\Core\StoreItemAsset;
use App\Entity\Core\Ticketing\TicketingItem;
use App\Exception\ValidationException;
use App\Service\JsonValidator;
use App\Voter\StoreItemVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Intervention\Image\ImageManagerStatic as Image;

class StoreItemAPIController extends Controller{
    /**
     * @Route("/api/store-items", methods={"GET"})
     */
    public function getStoreItems(Request $request) {
        if ($request->query->get("module")) {
            $repo = $this->getDoctrine()->getRepository(AbstractModule::class);
            $id = (int) $request->query->get("module");
            $userId = (int) $request->query->get("user");
            $module = $repo->find($id);
            if (!$module) {
                throw new NotFoundHttpException("Cannot find module");
            }
            $showTraded = $request->query->get("showTraded") == true;
            $showDisabled = $request->query->get("showDisabled") == true;
            $showExpired = $request->query->get("showExpired") == true;
            $query = preg_quote($request->query->get("queryStr"), "/");
            $rtn = [];
            /* @var AbstractModule $module */
            foreach ($module->getStoreFronts() as $storeFront) {
                /* @var AbstractStoreFront $storeFront */
                foreach ($storeFront->getStoreItems() as $storeItem) {
                    /* @var AbstractStoreItem $storeItem */
                    $shouldShow = true;
                    $shouldShow = $shouldShow && (empty($userId) || $userId == $storeItem->getStoreFront()->getOwner()->getId());
                    $shouldShow = $shouldShow && $storeItem->isActive($showTraded, $showDisabled, $showExpired);
                    if ($shouldShow) {
                        $param = [
                            $storeItem->getName(),
                            $storeItem->getDescription(),
                        ];
                        switch (get_class($storeItem)) {
                            case SecondHandItem::class:
                                break;
                            case HousingItem::class:
                                /* @var HousingItem $storeItem */
                                $param[] = $storeItem->getPropertyType();
                                $param[] = $storeItem->getLocation();
                                break;
                            case TicketingItem::class:
                                break;
                        }
                        $shouldShow = $shouldShow && (empty($query) || !empty(preg_grep("/".$query."/iu", $param)));
                    }
                    $em = $this->getDoctrine()->getManager();
                    if ($shouldShow) {
                        $storeItem->setVisitorCount($storeItem->getVisitorCount() + 1);
                        $em->persist($storeItem);
                        $arr = $storeItem->jsonSerialize();
                        if ($arr["assets"]) {
                            foreach (array_keys($arr["assets"]) as $key) {
                                $arr["assets"][$key] = $this->generateUrl("api_asset_get_item", ["id" => $arr["assets"][$key]],UrlGeneratorInterface::ABSOLUTE_URL);
                            }
                        }
                        $rtn[] = $arr;
                    }
                    $em->flush();
                }
            }
            usort($rtn, function($arr1, $arr2) {
                return -($arr1["lastTopTime"] <=> $arr2["lastTopTime"]);
            });
            return new JsonResponse($rtn);
        }
        throw new \Exception("Unsupported Methods");
    }

    /**
     * @Route("/api/store-items/{id}", methods={"GET"})
     */
    public function getStoreItem(int $id) {
        $repo = $this->getDoctrine()->getRepository(AbstractStoreItem::class);
        $storeItem = $repo->find($id);
        if (empty($storeItem)) {
            throw new NotFoundHttpException("Entity not found");
        }
        $storeItem->setVisitorCount($storeItem->getVisitorCount() + 1);
        $rtn = $storeItem->jsonSerialize();
        foreach (array_keys($rtn["assets"]) as $key) {
            $rtn["assets"][$key] = $this->generateUrl("api_asset_get_item", ["id" => $rtn["assets"][$key]],UrlGeneratorInterface::ABSOLUTE_URL);
        }
        $em = $this->getDoctrine()->getManager();
        $em->persist($storeItem);
        $em->flush();
        return new JsonResponse($rtn);
    }

    /**
     * @Route("/api/store-items/{id}/assets", methods={"POST"}, name="api_store_item_create_asset")
     */
    public function createAssets(int $id, Request $request, ParameterBagInterface $bag) {
        $repo = $this->getDoctrine()->getRepository(AbstractStoreItem::class);
        /* @var \App\Entity\Core\AbstractStoreItem $storeItem */
        $storeItem = $repo->find($id);
        if (empty($storeItem)) {
            throw new NotFoundHttpException("Cannot locate entity");
        }
        $this->denyAccessUnlessGranted(StoreItemVoter::UPDATE, $storeItem);
        /* @var \Symfony\Component\HttpFoundation\File\UploadedFile $file */
        $file = array_values($request->files->all())[0];
        $base64 = base64_encode(file_get_contents($file->getRealPath()));
        $asset = new StoreItemAsset();
        $asset->setNamespace(get_class($storeItem));
        $asset->setMimeType($file->getMimeType());
        $asset->setBase64($base64);
        $asset->setStoreItem($storeItem);
        $img = Image::make($asset->getBase64());
        if ($img->getWidth() > $img->getHeight()) {
            $img->resize($bag->get("thumbnail_max_width"), null, function(Constraint $constraint){
                $constraint->aspectRatio();
                $constraint->upsize();
            });
        } else {
            $img->resize(null, $bag->get("thumbnail_max_height"), function(Constraint $constraint){
                $constraint->aspectRatio();
                $constraint->upsize();
            });
        }
        $url = $img->encode("data-url");
        preg_match("/^data:.+;base64,(.+)/", $url, $match);
        $asset->setThumbnailBase64($match[1]);
        $em = $this->getDoctrine()->getManager();
        $em->persist($storeItem);
        $em->persist($asset);
        $em->flush();
        return new JsonResponse($storeItem);
    }

    /**
     * @Route("/api/store-items/assets/{id}", methods={"DELETE"}, name="api_store_item_delete_asset")
     */
    public function deleteAsset(int $id) {
        $repo = $this->getDoctrine()->getRepository(StoreItemAsset::class);
        $asset = $repo->find($id);
        if (empty($asset)) {
            throw new NotFoundHttpException("Cannot locate Entity");
        }
        $em = $this->getDoctrine()->getManager();
        $em->remove($asset);
        $em->flush();
        return new JsonResponse([
            "status" => "success"
        ]);
    }

    /**
     * @Route("/api/store-items/{id}", methods={"PUT"})
     * @throws ValidationException
     */
    public function updateItem(int $id, Request $request, JsonValidator $validator) {
        $repo = $this->getDoctrine()->getRepository(AbstractStoreItem::class);
        $storeItem = $repo->find($id);
        /* @var AbstractStoreItem $storeItem */
        if (empty($storeItem)) {
            throw new NotFoundHttpException("Cannot location entity");
        }
        $this->denyAccessUnlessGranted(StoreItemVoter::UPDATE, $storeItem);
        $json = json_decode($request->getContent(), true);
        $constraints = [
            "name" => [
                new Assert\NotBlank()
            ],
            "description" => [
            ],
            "weChatId" => [
            ],
            "price" => [
                new Assert\GreaterThanOrEqual([
                    "value" => 0
                ]),
                new Assert\Type([
                    "type" => "numeric"
                ])
            ],
            "effectiveDate" => [
                new Assert\Date([
                    "message" => "Invalid Date (yyyy-mm-dd)"
                ])
            ],
            "location" => [
                new Assert\Optional([
                    new Assert\NotBlank()
                ]),
            ],
            "propertyType" => [
                new Assert\NotBlank()
            ],
            "duration" => [
                new Assert\NotBlank()
            ],
            "isDisabled" => [
                new Assert\EqualTo([
                    "value" => true,
                    "message" => "Cannot undelete the item using API"
                ]),
                new Assert\Type([
                    "type" => "boolean"
                ])
            ],
            "currency" => [
                new Assert\Regex([
                    "pattern" => "/^RMB|GBP$/i",
                    "message" => "Can only be GBP or RMB"
                ])
            ]
        ];
        $validator->setAllowExtraFields(true);
        $validator->setAllowMissingFields(true);
        $validator->validate($json, $constraints);
        isset($json["name"]) && $storeItem->setName($json["name"]);
        isset($json["description"]) && $storeItem->setDescription($json["description"]);
        isset($json["weChatId"]) && $storeItem->setWeChatId($json["weChatId"]);
        isset($json["isTraded"]) && $storeItem->setIsTraded((bool) $json["isTraded"]);
        isset($json["isDisabled"]) && $storeItem->setIsDisabled((bool) $json["isDisabled"]);
        isset($json["price"]) && $storeItem->setPrice($json["price"]);
        isset($json["currency"]) && $storeItem->setCurrency($json["currency"]);
        switch (get_class($storeItem)) {
            case SecondHandItem::class:
                break;
            case HousingItem::class:
                isset($json["duration"]) && $storeItem->setDuration($json["duration"]);
                isset($json["propertyType"]) && $storeItem->setPropertyType($json["propertyType"]);
                isset($json["location"]) && $storeItem->setLocation($json["location"]);
                break;
            case TicketingItem::class:
                isset($json["effectiveDate"]) && $storeItem->setValidTill(\DateTimeImmutable::createFromFormat("Y-m-d", $json["effectiveDate"]));
                break;
        }
        $em = $this->getDoctrine()->getManager();
        $em->persist($storeItem);
        if (!empty($json["stickyTicket"])) {
            $tickets = $this->getDoctrine()->getRepository(StickyTicket::class)->findBy([
                "code" => $json["stickyTicket"]
            ]);
            if (!$tickets) {
                throw new NotFoundHttpException("Invalid Code");
            }
            $tickets = array_filter($tickets, function(StickyTicket $ticket){
                return !$ticket->isConsumed() && ($ticket->getExpireDate() > (new \DateTimeImmutable()));
            });
            $ticket = reset($tickets);
            /* @var StickyTicket $ticket */
            if (!$ticket) {
                throw new \Exception("Ticket expired or consumed.");
            }
            if ($ticket->getUser() && ($ticket->getUser() !== $this->getUser())) {
                throw new \Exception("This ticket is already used by another user.");
            }
            $now = new \DateTimeImmutable();
            $offset = $storeItem->getAutoTopFrequency();
            $storeFront = $storeItem->getStoreFront();
            switch (get_class($storeFront)) {
                case SecondHandStoreFront::class:
                    // SecondHandItem auto top logic is defined in storefront.
                    if ($storeFront->isAutoTop() && $now < $storeFront->getLastTopTime()->modify("+$offset hours")) {
                        throw new \Exception("Cannot change ordering within $offset hours");
                    }
                    $storeFront->setIsAutoTop(true);
                    $storeFront->setLastTopTime($now);
                    foreach ($storeFront->getStoreItems() as $storeItem) {
                        $storeItem->setIsAutoTop(true);
                        $storeItem->setLastTopTime($now);
                        $em->persist($storeItem);
                    }
                    $em->persist($storeFront);
                    break;
                default:
                    // Other type is defined in store item
                    if ($storeItem->isAutoTop() && $now < $storeItem->getLastTopTime()->modify("+$offset hours")) {
                        throw new \Exception("Cannot change ordering within $offset hours");
                    }
                    $storeItem->setIsAutoTop(true);
                    $storeItem->setLastTopTime($now);
                    $em->persist($storeItem);
                    break;
            }
            if (!$ticket->getUser()) {
                $ticket->setUser($storeItem->getStoreFront()->getOwner());
            }
            $ticket->setIsConsumed(true);
            $em->persist($ticket);
        }
        $em->flush();
        return new JsonResponse($storeItem);
    }

    /**
     * @Route("/api/store-items/{id}", methods={"DELETE"})
     */
    public function deleteItem(int $id) {
        $repo = $this->getDoctrine()->getRepository(AbstractStoreItem::class);
        /* @var \App\Entity\Core\AbstractStoreItem $storeItem */
        $storeItem = $repo->find($id);
        if (empty($storeItem)) {
            throw new NotFoundHttpException("Cannot locate entity");
        }
        $this->denyAccessUnlessGranted(StoreItemVoter::DELETE, $storeItem);
        $storeItem->setIsDisabled(true);
        $em = $this->getDoctrine()->getManager();
        $em->persist($storeItem);
        $em->flush();
        return new JsonResponse([
            "status" => "success"
        ]);
    }
}