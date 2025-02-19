@contentrepository @adapters=DoctrineDBAL,Postgres
Feature: Remove NodeAggregate

  As a user of the CR I want to be able to remove a NodeAggregate or parts of it.

  These are the test cases without dimensions being involved (so no partial removal)

  Background:
    Given using no content dimensions
    And using the following node types:
    """yaml
    'Neos.ContentRepository.Testing:Document':
      properties:
        references:
          type: references
    """
    And using identifier "default", I define a content repository
    And I am in content repository "default"
    And I am user identified by "initiating-user-identifier"
    And the command CreateRootWorkspace is executed with payload:
      | Key                  | Value                |
      | workspaceName        | "live"               |
      | newContentStreamId   | "cs-identifier"      |
    And I am in workspace "live" and dimension space point {}
    And the command CreateRootNodeAggregateWithNode is executed with payload:
      | Key             | Value                         |
      | nodeAggregateId | "lady-eleonode-rootford"      |
      | nodeTypeName    | "Neos.ContentRepository:Root" |
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId        | nodeTypeName                            | parentNodeAggregateId  | nodeName |
      | sir-david-nodenborough | Neos.ContentRepository.Testing:Document | lady-eleonode-rootford | document |
      | nodingers-cat          | Neos.ContentRepository.Testing:Document | lady-eleonode-rootford | pet      |
      | nodingers-kitten       | Neos.ContentRepository.Testing:Document | nodingers-cat          | kitten   |
    And the command SetNodeReferences is executed with payload:
      | Key                   | Value                                  |
      | sourceNodeAggregateId | "nodingers-cat"                        |
      | references            | [{"referenceName": "references", "references": [{"target": "sir-david-nodenborough"}]}] |

  Scenario: Remove a node aggregate
    When the command RemoveNodeAggregate is executed with payload:
      | Key                          | Value           |
      | nodeAggregateId              | "nodingers-cat" |
      | nodeVariantSelectionStrategy | "allVariants"   |
    Then I expect exactly 7 events to be published on stream with prefix "ContentStream:cs-identifier"
    And event at index 6 is of type "NodeAggregateWasRemoved" with payload:
      | Key                                  | Expected        |
      | contentStreamId                      | "cs-identifier" |
      | nodeAggregateId                      | "nodingers-cat" |
      | affectedOccupiedDimensionSpacePoints | [[]]            |
      | affectedCoveredDimensionSpacePoints  | [[]]            |
      | removalAttachmentPoint               | null            |
    Then I expect the graph projection to consist of exactly 2 nodes
    And I expect a node identified by cs-identifier;lady-eleonode-rootford;{} to exist in the content graph
    And I expect a node identified by cs-identifier;sir-david-nodenborough;{} to exist in the content graph
    And I expect node aggregate identifier "lady-eleonode-rootford" to lead to node cs-identifier;lady-eleonode-rootford;{}
    And I expect this node to have the following child nodes:
      | Name     | NodeDiscriminator                       |
      | document | cs-identifier;sir-david-nodenborough;{} |
    And the subtree for node aggregate "lady-eleonode-rootford" with node types "" and 1 levels deep should be:
      | Level | nodeAggregateId        |
      | 0     | lady-eleonode-rootford |
      | 1     | sir-david-nodenborough |
    And I expect node aggregate identifier "sir-david-nodenborough" and node path "document" to lead to node cs-identifier;sir-david-nodenborough;{}
    And I expect this node to be a child of node cs-identifier;lady-eleonode-rootford;{}
    And I expect this node to have no references
    And I expect node aggregate identifier "nodingers-cat" and node path "pet" to lead to no node
    And I expect node aggregate identifier "nodingers-kitten" and node path "pet/kitten" to lead to no node

  Scenario: Disable a node aggregate, remove it, recreate it and expect it to be enabled
    When the command DisableNodeAggregate is executed with payload:
      | Key                          | Value           |
      | nodeAggregateId              | "nodingers-cat" |
      | nodeVariantSelectionStrategy | "allVariants"   |
    And the command RemoveNodeAggregate is executed with payload:
      | Key                          | Value           |
      | nodeAggregateId              | "nodingers-cat" |
      | nodeVariantSelectionStrategy | "allVariants"   |
    And the command CreateNodeAggregateWithNode is executed with payload:
      | Key                   | Value                                     |
      | nodeAggregateId       | "nodingers-cat"                           |
      | nodeTypeName          | "Neos.ContentRepository.Testing:Document" |
      | parentNodeAggregateId | "lady-eleonode-rootford"                  |
      | nodeName              | "pet"                                     |

    Then I expect the node aggregate "nodingers-cat" to exist
    And I expect this node aggregate to disable dimension space points []
    And I expect the graph projection to consist of exactly 3 nodes
    And I expect a node identified by cs-identifier;lady-eleonode-rootford;{} to exist in the content graph
    And I expect a node identified by cs-identifier;sir-david-nodenborough;{} to exist in the content graph
    And I expect a node identified by cs-identifier;nodingers-cat;{} to exist in the content graph
    And I expect node aggregate identifier "lady-eleonode-rootford" to lead to node cs-identifier;lady-eleonode-rootford;{}
    And I expect this node to have the following child nodes:
      | Name     | NodeDiscriminator                       |
      | document | cs-identifier;sir-david-nodenborough;{} |
      | pet      | cs-identifier;nodingers-cat;{}          |
    And the subtree for node aggregate "lady-eleonode-rootford" with node types "" and 1 levels deep should be:
      | Level | nodeAggregateId        |
      | 0     | lady-eleonode-rootford |
      | 1     | sir-david-nodenborough |
      | 1     | nodingers-cat          |
    And I expect node aggregate identifier "sir-david-nodenborough" and node path "document" to lead to node cs-identifier;sir-david-nodenborough;{}
    And I expect this node to be a child of node cs-identifier;lady-eleonode-rootford;{}
    And I expect node aggregate identifier "nodingers-cat" and node path "pet" to lead to node cs-identifier;nodingers-cat;{}
    And I expect node aggregate identifier "nodingers-kitten" and node path "pet/kitten" to lead to no node

  Scenario: Remove a node aggregate, recreate it and expect it to have no references
    When the command RemoveNodeAggregate is executed with payload:
      | Key                          | Value           |
      | nodeAggregateId              | "nodingers-cat" |
      | nodeVariantSelectionStrategy | "allVariants"   |
    And the command CreateNodeAggregateWithNode is executed with payload:
      | Key                   | Value                                     |
      | nodeAggregateId       | "nodingers-cat"                           |
      | nodeTypeName          | "Neos.ContentRepository.Testing:Document" |
      | parentNodeAggregateId | "lady-eleonode-rootford"                  |
      | nodeName              | "pet"                                     |

    Then I expect node aggregate identifier "sir-david-nodenborough" and node path "document" to lead to node cs-identifier;sir-david-nodenborough;{}
    And I expect this node to be a child of node cs-identifier;lady-eleonode-rootford;{}
    And I expect this node to not be referenced
    And I expect node aggregate identifier "nodingers-cat" and node path "pet" to lead to node cs-identifier;nodingers-cat;{}
    And I expect this node to have no references
    And I expect node aggregate identifier "nodingers-kitten" and node path "pet/kitten" to lead to no node

  Scenario: Remove a node aggregate with descendants and expect all of them to be gone
    When the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId        | nodeTypeName                            | parentNodeAggregateId  | nodeName |
      | nody-mc-nodeface | Neos.ContentRepository.Testing:Document | sir-david-nodenborough | child |
      | younger-mc-nodeface | Neos.ContentRepository.Testing:Document | sir-david-nodenborough | younger-child |
    When the command RemoveNodeAggregate is executed with payload:
      | Key                          | Value           |
      | nodeAggregateId              | "sir-david-nodenborough" |
      | nodeVariantSelectionStrategy | "allVariants"   |

    Then I expect node aggregate identifier "sir-david-nodenborough" and node path "document" to lead to no node
    And I expect node aggregate identifier "nody-mc-nodeface" and node path "document/child" to lead to no node
    And I expect node aggregate identifier "younger-mc-nodeface" and node path "document/younger-child" to lead to no node
