## MediaWiki Stakeholders Group - Components
# Datastash - persistent key/value store for user data

**This code is meant to be executed within the MediaWiki application context. No standalone usage is intended.**

## Usage

Data can be stashed for current wiki or globally, accessible across wikis in the same cluster. The API is simple:

```php
/** @var \MWStake\MediaWiki\Component\DataStash\StashManager $stash */
$stash = \MediaWiki\MediaWikiServices::getInstance()->getService( 'MWStake.DataStash' );
$user = \RequestContext::getMain()->getUser();

$stash->stash( 'myKey', [ 'some' => 'value' ], $user );

// or globally
$stash->stashGlobal( 'myKeyGlobal', [ 'some' => 'value' ], $user );

var_dump( $stash->get( 'myKey', $user ) );
// outputs: [ 'some' => 'value' ]

var_dump( $stash->getGlobal( 'myKeyGlobal', $user ) );
// outputs: [ 'some' => 'value' ]
```

It is possible to store same key both globally and locally, although not recommended.

### REST

`GET rest.php/mws/v1/data-stash/{key}` - get stashed data for current user.
- `key` - key of the stashed data
- query param `global` - if set to `1`, global stash will be returned, otherwise local stash for current wiki


`POST rest.php/mws/v1/data-stash/{key}` - stash data for current user.
- `key` - key of the stashed data
- `body`
```json
{
    "data": { "some": "value" },
    "global": true
}
```
