@flowEntities
Feature: EditNodePrivilege related features

  Background:
    Given The following additional policies are configured:
      """
      privilegeTargets:
        'Neos\Neos\Security\Authorization\Privilege\EditNodePrivilege':
          'Neos.Neos:EditBlog':
            matcher: 'blog'
      """
    And using the following content dimensions:
      | Identifier | Values                | Generalizations                     |
      | language   | mul, de, en, gsw, ltz | ltz->de->mul, gsw->de->mul, en->mul |
    And using the following node types:
    """yaml
    'Neos.Neos:Document': {}
    """
    And using identifier "default", I define a content repository
    And I am in content repository "default"
    And the command CreateRootWorkspace is executed with payload:
      | Key                | Value           |
      | workspaceName      | "live"          |
      | newContentStreamId | "cs-identifier" |
    And I am in workspace "live" and dimension space point {}
    And the command CreateRootNodeAggregateWithNode is executed with payload:
      | Key             | Value                         |
      | nodeAggregateId | "root"                        |
      | nodeTypeName    | "Neos.ContentRepository:Root" |
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId | nodeTypeName       | parentNodeAggregateId | nodeName | originDimensionSpacePoint |
      | a               | Neos.Neos:Document | root                  | a        | {"language":"mul"}        |
      | a1              | Neos.Neos:Document | a                     | a1       | {"language":"de"}         |
      | a1a             | Neos.Neos:Document | a1                    | a1a      | {"language":"de"}         |
      | a1a1            | Neos.Neos:Document | a1a                   | a1a1     | {"language":"de"}         |
      | a1a1a           | Neos.Neos:Document | a1a1                  | a1a1a    | {"language":"de"}         |
      | a1a1b           | Neos.Neos:Document | a1a1                  | a1a1b    | {"language":"de"}         |
      | a1a2            | Neos.Neos:Document | a1a                   | a1a2     | {"language":"de"}         |
      | a1b             | Neos.Neos:Document | a1                    | a1b      | {"language":"de"}         |
      | a2              | Neos.Neos:Document | a                     | a2       | {"language":"de"}         |
      | b               | Neos.Neos:Document | root                  | b        | {"language":"de"}         |
      | b1              | Neos.Neos:Document | b                     | b1       | {"language":"de"}         |
    And the following Neos users exist:
      | Username | First name | Last name | Roles                                            |
      | jane.doe | Jane       | Doe       | Neos.Neos:Administrator                          |
      | john.doe | John       | Doe       | Neos.Neos:RestrictedEditor,Neos.Neos:UserManager |
      | editor   | Edward     | Editor    | Neos.Neos:Editor                                 |

  Scenario: TODO
    Given I am in workspace "live"
    And I am in dimension space point {"language":"de"}
    And the command TagSubtree is executed with payload:
      | Key                          | Value                |
      | nodeAggregateId              | "a"                  |
      | nodeVariantSelectionStrategy | "allSpecializations" |
      | tag                          | "blog"               |
    And the role MANAGER is assigned to workspace "live" for user "jane.doe"
    When content repository security is enabled
    And I am authenticated as "jane.doe"
    When the command DisableNodeAggregate is executed with payload and exceptions are caught:
      | Key                          | Value         |
      | nodeAggregateId              | "a1a"         |
      | nodeVariantSelectionStrategy | "allVariants" |
    Then the last command should have thrown an exception of type "AccessDenied" with code 1729086686
#    Then the last command should have thrown an exception of type "AccessDenied" with message:
#    """
#    Command "Neos\ContentRepository\Core\Feature\NodeDisabling\Command\DisableNodeAggregate" was denied: No edit permissions for node "a1a" in workspace "live": Evaluated following 2 privilege target(s):
#    "Neos.Neos:ReadBlog": ABSTAIN
#    "Neos.Neos:ReadBlog": GRANT
#    (1 granted, 0 denied, 1 abstained)
#    Evaluated following 1 privilege target(s):
#    "Neos.Neos:EditBlog": ABSTAIN
#    (0 granted, 0 denied, 1 abstained)
#    """
