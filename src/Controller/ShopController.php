<?php

namespace App\Controller;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use App\Form\AddProductType;
use App\Form\AddToCartType;
use App\Form\CartReserveType;
use App\Form\CartType;
use App\Form\ProductModifType;
use App\Manager\CartManager;
use Knp\Component\Pager\PaginatorInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use App\Storage\CartSessionStorage;


/**
 * @Route("/boutique", name="shop_")
 */
class ShopController extends AbstractController
{

    //page du stock

    /**
     * @Route("/produit-stock", name="stock")
     * @Security("is_granted('ROLE_ADMIN')")
     */
    public function stock(Request $request): Response
    {


        $em = $this->getDoctrine()->getManager();
        $productsRepo = $em->getRepository(Product::class);
        $products = $productsRepo->findBy([],['stock' => 'ASC']);

        return $this->render('shop/stock.html.twig', [
            'products' => $products
        ]);
    }
    //display produit list
    /**
     * @Route("/produit", name="product")
     */
    public function product(Request $request, PaginatorInterface $paginator): Response
    {
        $requestedPage = $request->query->getInt('page', 1);
        if($requestedPage < 1){
            throw new NotFoundHttpException();
        }
        $em = $this->getDoctrine()->getManager();
        $query = $em->createQuery('SELECT a FROM App\Entity\Product a');

        $pageProduct = $paginator->paginate(
            $query,
            $requestedPage,
            16
        );

        return $this->render('shop/product.html.twig',[
            'product' => $pageProduct,
        ]);
    }




    //ajout de produit et changement du nom de la photo pour la securité ! never trust the user !

    /**
     * @Route("/ajouter-produit", name="product.add")
     * @Security("is_granted('ROLE_ADMIN')")
     */
    public function productadd(Request $request): Response
    {
        //creation du nouvel object product et hydration par le formulaire envoyer dans la vue
        $product = new Product();
        $form = $this->createForm(AddProductType::class,$product);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()){
            $photo = $form->get('photo')->getData();
            //changement du nom du fichier
            do{
                $newFileName = md5(random_bytes(100)).'.'. $photo->guessExtension();

            }while(file_exists('public/img/produit/'. $newFileName));
            $product->setPhoto($newFileName);
            $em = $this->getDoctrine()->getManager();
            $em->persist($product);
            $em->flush();
            //mise en place de la photo dans sont dossier prévue
            $photo->move(
                'img/produit/',
                $newFileName
            );

            return $this->redirectToRoute('shop_product');

        }


        return $this->render('shop/addproduct.html.twig',[
            'form' => $form->createView()

        ]);
    }

    //modification des produit administration

    /**
     * @Route("/modif-produit/{slug}", name="product_modif")
     * @Security("is_granted('ROLE_ADMIN')")
     */
    public function modifProduit(Request $request, Product $product): Response
    {

        $form = $this->createForm(ProductModifType::class,$product);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()){

            $em = $this->getDoctrine()->getManager();
            $em->flush();

            return $this->redirectToRoute('shop_stock');

        }


        return $this->render('shop/modifproduct.html.twig',[
            'form' => $form->createView()

        ]);
    }

    //supression de produit via leur id + protection contre csrf

    /**
     * @Route("/produit/delete/{id}", name="produit.delete")
     * @Security ("is_granted('ROLE_ADMIN')")
     */
    public function produitDelete(Product $product, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('product_delete_' . $product->getId(), $request->query->get('csrf_token'))) {
            $this->addFlash('error', 'token secu invalide reessayer');
        } else {

            $em = $this->getDoctrine()->getManager();

            $em->remove($product);

            $em->flush();

            $this->addFlash('success', 'le produit a bien eté surpprimé');

        }
        return $this->redirectToRoute('shop_stock');


    }

    //produit en détail avec ajout au panier

    /**
     * @Route("/produit/{slug}", name="product.detail")
     */
    public function productdesc(Product $product, Request $request, CartManager $cartManager): Response
    {
        $form = $this->createForm(AddToCartType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $item = $form->getData();
            $item->setProduct($product);
            //verification de la disponibilité du produit dans le stock
            if ($product->getStock() >= $item->getQuantity()){

            $cart = $cartManager->getCurrentCart();
            $cart
                ->addItem($item)
                ->setUpdatedAt(new \DateTime())
            ;


            $cartManager->save($cart);
            $this->addFlash('success','Produits ajouter au panier');
            return $this->redirectToRoute('shop_product');
            }else{
                $this->addFlash('error','Le produit n\'est plus en stock');
            }

        }

        return $this->render('shop/detail.html.twig',[
            'product' => $product,
            'form' => $form->createView(),
        ]);
    }

    // panier + form reservation

    /**
     * @Route("/cart", name="panier")
     */
    public function panier(CartManager $cartManager, Request $request, CartSessionStorage $cartSessionStorage): Response
    {
        //recuperation du panier via le service cart manager
        $cart = $cartManager->getCurrentCart();
        //formulaire du modifer panier et vider panier
        $form = $this->createForm(CartType::class, $cart);
        //formulaire de reservation
        $formreserve = $this->createForm(CartReserveType::class,$cart);

        $formreserve->handleRequest($request);
        $form->handleRequest($request);
        //validation côté back du formulaire pour les modififications de quantité
        if ($form->isSubmitted() && $form->isValid()){
            $cart->setUpdatedAt(new \DateTime());
            $cartManager->save($cart);

            return $this->redirectToRoute('shop_panier');
        }

        //validation formulaire reservation
        if ($formreserve->isSubmitted() && $formreserve->isValid()){
            $date = new \DateTime();
            //verif pour etre dans une periode de 15 jours
            if($cart->getDateReservation() > $date && $cart->getDateReservation() < $date->add(new \DateInterval('P15D')))
            {
                //delet de l'id du cart en session
                $cartSessionStorage->deleteCart();
                // hydratation de l'acheteur et de la date
                $cart->setBuyer($this->getUser());
                $id = $cart->getId();
                //modification du stock au moment de la reservation
                $em = $this->getDoctrine()->getManager();
                $orderitemrepo = $em->getRepository(OrderItem::class);
                $orderitem =  $orderitemrepo->findByOrderRef($id);
                foreach ( $orderitem as $item){
                    $product = $item->getProduct();
                    $product->setStock($product->getStock() - $item->getQuantity());
                }

                $em = $this->getDoctrine()->getManager();
                $em->flush();
                $this->addFlash('success','Votre panier a bien été réservé');
                return $this->redirectToRoute('shop_reservation_client', [
                    'id' => $this->getUser()->getId()
                    ]
                );
            }else {
                $this->addFlash('error','la date doit etre conforme au maximum 15 jours');
            }


        }

        return $this->render('shop/cart.html.twig', [
            'cart' => $cart,
            'form' => $form->createView(),
            'formreserve' => $formreserve->createView(),
        ]);
    }

    // envoie des reservation pour le client dans la vue
    /**
     * @Route("/mes-reservation/{id}", name="reservation_client")
     *
     */
    public function reservation(): Response
    {

        $orderRepo = $this->getDoctrine()->getRepository(Order::class);
        $order = $orderRepo->findByBuyer($this->getUser());
        return $this->render('shop/reservation.html.twig',[
            'order' => $order
        ]);
    }


    //envoie des reservations de tout les clients dans la vue administration du site

    /**
     * @Route("/reservation", name="reservation")
     * @Security("is_granted('ROLE_ADMIN')")
     */
    public function reservationclient(): Response
    {

        $em = $this->getDoctrine()->getManager();
        $query = $em->createQuery("SELECT a FROM App\Entity\Order a WHERE a.buyer is not null ");
        $order = $query->getResult();
        return $this->render('shop/reservationall.html.twig',[
            'order' => $order
        ]);

    }
    //validation du retrait de la reservation
    /**
     * @Route("/validation{id}", name="reservation.validation")
     * @Security("is_granted('ROLE_ADMIN')")
     */
    public function validationreservationclient(Order $order): Response
    {
        $em = $this->getDoctrine()->getManager();
        $em->remove($order);
        $em->flush();


        return $this->redirectToRoute('shop_reservation');

    }
    //suppression reservation par admin remise en stock des produit non retiré

    /**
     * @Route("/produit-annulation{id}", name="cart.cancel")
     * @Security("is_granted('ROLE_ADMIN')")
     */
    public function cartCancel(Order $order): Response
    {


        $id = $order->getId();
        $em = $this->getDoctrine()->getManager();

        //recherche des produits dans le panier

        $orderItemRepo = $em->getRepository(OrderItem::class);
        $orderItem =  $orderItemRepo->findByOrderRef($id);

        //foreach qui supprime les produits du panier
        foreach ( $orderItem as $item){
            $product = $item->getProduct();
            $product->setStock($product->getStock() + $item->getQuantity());
        }
        //supression de l'order
        $em->remove($order);
        $em->flush();

        return $this->redirectToRoute('shop_reservation');
    }

//supression des panier non reserver

    /**
     * @Route("/annulationpannier", name="cart.cancel.all")
     * @Security("is_granted('ROLE_ADMIN')")
     */
    public function cartCancelall(): Response
    {

        $em = $this->getDoctrine()->getManager();
        //requete visant a remonter les produit d'un panier sans reservation et datant de plus de 2  jour
        $date = new \DateTime();
        $date->sub(new \DateInterval('P2D'));

        //requete resortant les produits et les paniers vieux de deux jours sans acheteur
        $querybuild = $em->createQueryBuilder('a')
            ->select('a')
            ->from('App\Entity\OrderItem','a')
            ->innerJoin('a.orderRef','b')
            ->where('b.buyer is NULL')
            ->andWhere('b.updatedAt = :date')
            ->setParameter(':date',$date)
            ->getQuery()
            ->getResult();
        //foreach pour suprimer les produit et les reservation lié a ceux-ci
        foreach ( $querybuild as $item){
            $order = $item->getOrderRef();
            $em->remove($item);
            $em->remove($order);
        }
        $em->flush();

        return $this->redirectToRoute('shop_reservation');
    }


    //envoie des produit via la recherche de la navbar

    /**
     * @Route("/produit-recherche", name="search")
     */
    public function shopsearch(Request $request): Response
    {


        $research = $request->query->get('searcharea');
        $em = $this->getDoctrine()->getManager();
        $query = $em->createQuery("SELECT a FROM App\Entity\Product a WHERE a.name LIKE :key OR a.description LIKE :key ")->setParameter('key', '%' . $research . '%');
        $product = $query->getResult();

        return $this->render('shop/search.html.twig', [
            'product' => $product
        ]);
    }

    //envoie des produits par gammes via les boutons frontpage

    /**
     * @Route("/produit-gammes", name="gammes")
     */
    public function shopgammes(Request $request): Response
    {


        $research = $request->query->get('searcharea');
        $em = $this->getDoctrine()->getManager();

        $querybuild = $em->createQueryBuilder('a')
            ->select('a')
            ->from('App\Entity\Product','a')
            ->innerJoin('a.gammes','b')
            ->where('b.name = :name')
            ->setParameter(':name',$research)
            ->getQuery()
            ->getResult();
        return $this->render('shop/search.html.twig', [
            'product' => $querybuild
        ]);
    }

}
