<?php

namespace Kunstmaan\NodeBundle\Repository;

use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\Query;
use Gedmo\Tree\Entity\Repository\NestedTreeRepository;
use Kunstmaan\AdminBundle\Entity\User as Baseuser;
use Kunstmaan\AdminBundle\Helper\Security\Acl\AclHelper;
use Kunstmaan\AdminBundle\Helper\Security\Acl\AclNativeHelper;
use Kunstmaan\AdminBundle\Helper\Security\Acl\Permission\PermissionDefinition;
use Kunstmaan\NodeBundle\Entity\HasNodeInterface;
use Kunstmaan\NodeBundle\Entity\Node;
use Kunstmaan\NodeBundle\Entity\NodeVersion;
use Kunstmaan\NodeBundle\Entity\NodeTranslation;
use Kunstmaan\UtilitiesBundle\Helper\ClassLookup;

/**
 * NodeRepository
 */
class NodeRepository extends NestedTreeRepository
{
    /**
     * @param string    $lang                 The locale
     * @param string    $permission           The permission (read, write, ...)
     * @param AclHelper $aclHelper            The acl helper
     * @param bool      $includeHiddenFromNav include the hiddenfromnav nodes or not
     *
     * @return Node[]
     */
    public function getTopNodes($lang, $permission, AclHelper $aclHelper, $includeHiddenFromNav = false)
    {
        $result = $this->getChildNodes(null, $lang, $permission, $aclHelper, $includeHiddenFromNav);

        return $result;
    }

    /**
     * @param HasNodeInterface $hasNode
     *
     * @return Node|null
     */
    public function getNodeFor(HasNodeInterface $hasNode)
    {
        /* @var NodeVersion $nodeVersion */
        $nodeVersion = $this->getEntityManager()->getRepository('KunstmaanNodeBundle:NodeVersion')->getNodeVersionFor($hasNode);
        if (!is_null($nodeVersion)) {
            /* @var NodeTranslation $nodeTranslation */
            $nodeTranslation = $nodeVersion->getNodeTranslation();
            if (!is_null($nodeTranslation)) {
                return $nodeTranslation->getNode();
            }
        }

        return null;
    }

    /**
     * @param int    $id         The id
     * @param string $entityName The class name
     *
     * @return Node|null
     */
    public function getNodeForIdAndEntityname($id, $entityName)
    {
        /* @var NodeVersion $nodeVersion */
        $nodeVersion = $this->getEntityManager()->getRepository('KunstmaanNodeBundle:NodeVersion')->findOneBy(array('refId' => $id, 'refEntityName' => $entityName));
        if ($nodeVersion) {
            return $nodeVersion->getNodeTranslation()->getNode();
        }

        return null;
    }

    /**
     * @param Node   $parentNode The parent node (may be null)
     * @param string $slug       The slug
     *
     * @return Node|null
     */
    public function getNodeForSlug(Node $parentNode, $slug)
    {
        $slugParts = explode("/", $slug);
        $result = null;
        foreach ($slugParts as $slugPart) {
            if ($parentNode) {
                if ($r = $this->findOneBy(array('slug' => $slugPart, 'parent.parent' => $parentNode->getId()))) {
                    $result = $r;
                }
            } else {
                if ($r = $this->findOneBy(array('slug' => $slugPart))) {
                    $result = $r;
                }
            }
        }

        return $result;
    }

    /**
     * @param HasNodeInterface $hasNode      The object to link to
     * @param string           $lang         The locale
     * @param Baseuser         $owner        The user
     * @param string           $internalName The internal name (may be null)
     *
     * @throws \InvalidArgumentException
     *
     * @return Node
     */
    public function createNodeFor(HasNodeInterface $hasNode, $lang, Baseuser $owner, $internalName = null)
    {
        $em = $this->getEntityManager();
        $node = new Node();
        $node->setRef($hasNode);
        if (!$hasNode->getId() > 0) {
            throw new \InvalidArgumentException("the entity of class " . $node->getRefEntityName() . " has no id, maybe you forgot to flush first");
        }
        $node->setDeleted(false);
        $node->setInternalName($internalName);
        $parent = $hasNode->getParent();
        if ($parent) {
            /* @var NodeVersion $parentNodeVersion */
            $parentNodeVersion = $em->getRepository('KunstmaanNodeBundle:NodeVersion')->findOneBy(array('refId' => $parent->getId(), 'refEntityName' => ClassLookup::getClass($parent)));
            if ($parentNodeVersion) {
                $node->setParent($parentNodeVersion->getNodeTranslation()->getNode());
            }
        }
        $em->persist($node);
        $em->flush();
        $em->refresh($node);
        $em->getRepository('KunstmaanNodeBundle:NodeTranslation')->createNodeTranslationFor($hasNode, $lang, $node, $owner);

        return $node;
    }

    /**
     * @param int|null  $parentId             The parent id
     * @param string    $lang                 The locale
     * @param string    $permission           The permission (read, write, ...)
     * @param AclHelper $aclHelper            The acl helper
     * @param bool      $includeHiddenFromNav Include nodes hidden from navigation or not
     *
     * @return Node[]
     */
    public function getChildNodes($parentId, $lang, $permission, AclHelper $aclHelper, $includeHiddenFromNav = false)
    {
        $qb = $this->createQueryBuilder('b')
                   ->select('b')
                   ->leftJoin('b.nodeTranslations', 't', 'WITH', 't.lang = :lang')
                   ->where('b.deleted = 0');

        if (!$includeHiddenFromNav) {
            $qb->andWhere('b.hiddenFromNav != true');
        }

        if (is_null($parentId)) {
            $qb->andWhere('b.parent is NULL');
        } else {
            $qb->andWhere('b.parent = :parent')
               ->setParameter('parent', $parentId);
        }
        $qb->addOrderBy('t.weight', 'ASC')
           ->addOrderBy('t.title', 'ASC');
        $qb->setParameter('lang', $lang);
        $query = $aclHelper->apply($qb, new PermissionDefinition(array($permission)));

        return $query->getResult();
    }

    /**
     * Get all the information needed to build a menu tree with one query.
     * We only fetch the fields we need, instead of fetching full objects to limit the memory usage.
     *
     * @param string          $lang                 The locale
     * @param string          $permission           The permission (read, write, ...)
     * @param AclNativeHelper $aclNativeHelper            The acl helper
     * @param bool            $includeHiddenFromNav Include nodes hidden from navigation or not
     *
     * @return array
     */
    public function getAllMenuNodes($lang, $permission, AclNativeHelper $aclNativeHelper, $includeHiddenFromNav = false)
    {
        $qb = $this->_em->getConnection()->createQueryBuilder();
        $qb->select('n.id, n.parent_id AS parent,
                        IF(t.weight IS NULL, v.weight, t.weight) AS weight,
                        IF(t.title IS NULL, v.title, t.title) AS title,
                        IF(t.online IS NULL, 0, t.online) AS online')
            ->from('kuma_nodes', 'n')
            ->leftJoin('n', 'kuma_node_translations', 't', "(t.node_id = n.id AND t.lang = ?)")
            ->leftJoin('n', '(SELECT lang, title, weight, node_id FROM kuma_node_translations GROUP BY node_id ORDER BY id ASC)', 'v', "(v.node_id = n.id AND v.lang <> ?)")
            ->where('n.deleted = 0')
            ->addGroupBy('n.id')
            ->addOrderBy('parent', 'ASC')
            ->addOrderBy('weight', 'ASC')
            ->addOrderBy('title', 'ASC');

        if (!$includeHiddenFromNav) {
            $qb->andWhere('n.hiddenFromNav <> 0');
        }

        $permissionDef = new PermissionDefinition(array($permission));
        $permissionDef->setEntity('Kunstmaan\NodeBundle\Entity\Node');
        $permissionDef->setAlias('n');
        $qb = $aclNativeHelper->apply($qb, $permissionDef);

        $stmt = $this->_em->getConnection()->prepare($qb->getSQL());
        $stmt->bindValue(1, $lang);
        $stmt->bindValue(2, $lang);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Get all parents of a given node. We can go multiple levels up.
     *
     * @param Node $node
     * @param string $lang
     * @return Node[]
     */
    public function getAllParents(Node $node = null, $lang = null)
    {
        if (is_null($node)) {
            return array();
        }

        $qb = $this->createQueryBuilder('node');

        if ($lang) {
            // Directly fetch the nodeTranslation in one query
            $qb->select('node, t')
                ->innerJoin('node.nodeTranslations', 't')
                ->where('node.deleted = 0')
                ->andWhere('t.lang = :lang')
                ->setParameter('lang', $lang);
        } else {
            $qb->select('node')
                ->where('node.deleted = 0');
        }

        $qb->andWhere($qb->expr()->andX(
            $qb->expr()->lte('node.lft', $node->getLeft()),
            $qb->expr()->gte('node.rgt', $node->getRight())
        ));
        $qb->addOrderBy('node.lft', 'ASC');

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Node[]
     */
    public function getAllTopNodes()
    {
        $qb = $this->createQueryBuilder('b')
                   ->select('b')
                   ->where('b.deleted = 0')
                   ->andWhere('b.parent IS NULL');

        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Get an array of Nodes based on the internal name.
     *
     * @param string        $internalName   The internal name of the node
     * @param string        $lang           The locale
     * @param int|null|bool $parentId       The parent id
     * @param bool          $includeOffline Include offline nodes
     *
     * @return Node[]
     */
    public function getNodesByInternalName($internalName, $lang, $parentId = false, $includeOffline = false)
    {
        $qb = $this->createQueryBuilder('n')
            ->select('n, t')
            ->innerJoin('n.nodeTranslations', 't')
            ->where('n.deleted = 0')
            ->andWhere('n.internalName = :internalName')
            ->setParameter('internalName', $internalName)
            ->andWhere('t.lang = :lang')
            ->setParameter('lang', $lang)
            ->addOrderBy('t.weight', 'ASC')
            ->addOrderBy('t.title', 'ASC');

        if (!$includeOffline) {
            $qb->andWhere('t.online = true');
        }

        if (is_null($parentId)) {
            $qb->andWhere('n.parent is NULL');
        } elseif ($parentId === false) {
            // Do nothing
        } else {
            $qb->andWhere('n.parent = :parent')
                ->setParameter('parent', $parentId);
        }

        $query = $qb->getQuery();

        return $query->getResult();
    }
}
