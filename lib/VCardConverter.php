<?php

namespace Sabre\VObject;

/**
 * This utility converts vcards from one version to another.
 *
 * @copyright Copyright (C) 2007-2014 fruux GmbH. All rights reserved.
 * @author Evert Pot (http://evertpot.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */
class VCardConverter {

    /**
     * Converts a vCard object to a new version.
     *
     * targetVersion must be one of:
     *   Document::VCARD21
     *   Document::VCARD30
     *   Document::VCARD40
     *
     * Currently only 3.0 and 4.0 as input and output versions.
     *
     * 2.1 has some minor support for the input version, it's incomplete at the
     * moment though.
     *
     * If input and output version are identical, a clone is returned.
     *
     * @param Component\VCard $input
     * @param int $targetVersion
     */
    function convert(Component\VCard $input, $targetVersion) {

        $inputVersion = $input->getDocumentType();
        if ($inputVersion===$targetVersion) {
            return clone $input;
        }

        if (!in_array($inputVersion, [Document::VCARD21, Document::VCARD30, Document::VCARD40])) {
            throw new \InvalidArgumentException('Only vCard 2.1, 3.0 and 4.0 are supported for the input data');
        }
        if (!in_array($targetVersion, [Document::VCARD30, Document::VCARD40])) {
            throw new \InvalidArgumentException('You can only use vCard 3.0 or 4.0 for the target version');
        }

        $newVersion = $targetVersion===Document::VCARD40?'4.0':'3.0';

        $output = new Component\VCard([
            'VERSION' => $newVersion,
        ]);

        foreach($input->children as $property) {

            $this->convertProperty($input, $output, $property, $targetVersion);

        }

        return $output;

    }

    /**
     * Handles conversion of a single property.
     *
     * @param Component\VCard $input
     * @param Component\VCard $output
     * @param Property $property
     * @param int $targetVersion
     * @return void
     */
    protected function convertProperty(Component\VCard $input, Component\VCard $output, Property $property, $targetVersion) {

        // Skipping these, those are automatically added.
        if (in_array($property->name, ['VERSION', 'PRODID'])) {
            return;
        }

        $parameters = $property->parameters();

        $valueType = null;
        if (isset($parameters['VALUE'])) {
            $valueType = $parameters['VALUE']->getValue();
            unset($parameters['VALUE']);
        }
        if (!$valueType) {
            $valueType = $property->getValueType();
        }

        $newProperty = null;

        if ($targetVersion===Document::VCARD30) {

            if ($property instanceof Property\Uri && in_array($property->name, ['PHOTO','LOGO','SOUND'])) {

                $newProperty = $this->convertUriToBinary($output, $property, $parameters);

            } elseif ($property instanceof Property\VCard\DateAndOrTime) {

                // In vCard 4, the birth year may be optional. This is not the
                // case for vCard 3. Apple has a workaround for this that
                // allows applications that support Apple's extension still
                // omit birthyears in vCard 3, but applications that do not
                // support this, will just use a random birthyear. We're
                // choosing 1604 for the birthyear, because that's what apple
                // uses.
                $parts = DateTimeParser::parseVCardDateTime($property->getValue());
                if (is_null($parts['year'])) {
                    $newValue = '1604-' . $parts['month'] . '-' . $parts['date'];
                    $newProperty = $output->createProperty(
                        $property->name,
                        $newValue,
                        [
                            'X-APPLE-OMIT-YEAR' => '1604'
                        ],
                        $valueType
                    );

                }

            } elseif ($property->name === 'KIND') {

                switch(strtolower($property->getValue())) {
                    case 'org' :
                        // OS X addressbook property.
                        $newProperty = $output->createProperty('X-ABSHOWAS','COMPANY');
                        break;
                    case 'individual' :
                        // Individual is implied, so we can just skip it.
                        return;

                    case 'group' :
                        // OS X addressbook property
                        $newProperty = $output->createProperty('X-ADDRESSBOOKSERVER-KIND','GROUP');
                        break;
                }


            }

        } elseif ($targetVersion===Document::VCARD40) {

            // These properties were removed in vCard 4.0
            if (in_array($property->name, ['NAME', 'MAILER', 'LABEL', 'CLASS'])) {
                return;
            }

            if ($property instanceof Property\Binary) {

                $newProperty = $this->convertBinaryToUri($output, $property, $parameters);

            } elseif ($property instanceof Property\VCard\DateAndOrTime && isset($parameters['X-APPLE-OMIT-YEAR'])) {

                // If a property such as BDAY contained 'X-APPLE-OMIT-YEAR',
                // then we're stripping the year from the vcard 4 value.
                $parts = DateTimeParser::parseVCardDateTime($property->getValue());
                if ($parts['year']===$property['X-APPLE-OMIT-YEAR']->getValue()) {
                    $newValue = '--' . $parts['month'] . '-' . $parts['date'];
                    $newProperty = $output->createProperty(
                        $property->name,
                        $newValue,
                        [],
                        $valueType
                    );
                }

                // Regardless if the year matched or not, we do need to strip
                // X-APPLE-OMIT-YEAR.
                unset($parameters['X-APPLE-OMIT-YEAR']);

            } else {
                switch($property->name) {
                    case 'X-ABSHOWAS' :
                        if (strtoupper($property->getValue()) === 'COMPANY') {
                            $newProperty = $output->createProperty('KIND','org');
                        }
                        break;
                    case 'X-ADDRESSBOOKSERVER-KIND' :
                        if (strtoupper($property->getValue()) === 'GROUP') {
                            $newProperty = $output->createProperty('KIND','group');
                        }
                        break;
                }

            }

        }


        if (is_null($newProperty)) {

            $newProperty = $output->createProperty(
                $property->name,
                $property->getParts(),
                [], // no parameters yet
                $valueType
            );

        }

        // set property group
        $newProperty->group = $property->group;

        if ($targetVersion===Document::VCARD40) {
            $this->convertParameters40($newProperty, $parameters);
        } else {
            $this->convertParameters30($newProperty, $parameters);
        }

        // Lastly, we need to see if there's a need for a VALUE parameter.
        //
        // We can do that by instantating a empty property with that name, and
        // seeing if the default valueType is identical to the current one.
        $tempProperty = $output->createProperty($newProperty->name);
        if ($tempProperty->getValueType() !== $newProperty->getValueType()) {
            $newProperty['VALUE'] = $newProperty->getValueType();
        }

        $output->add($newProperty);


    }

    /**
     * Converts a BINARY property to a URI property.
     *
     * vCard 4.0 no longer supports BINARY properties.
     *
     * @param Component\VCard $output
     * @param Property\Uri $property The input property.
     * @param $parameters List of parameters that will eventually be added to
     *                    the new property.
     * @return Property\Uri
     */
    protected function convertBinaryToUri(Component\VCard $output, Property\Binary $property, array &$parameters) {

        $newProperty = $output->createProperty(
            $property->name,
            null, // no value
            [], // no parameters yet
            'URI' // Forcing the BINARY type
        );

        $mimeType = 'application/octet-stream';

        // See if we can find a better mimetype.
        if (isset($parameters['TYPE'])) {

            $newTypes = [];
            foreach($parameters['TYPE']->getParts() as $typePart) {
                if (in_array(
                    strtoupper($typePart),
                    ['JPEG','PNG','GIF']
                )) {
                    $mimeType = 'image/' . strtolower($typePart);
                } else {
                    $newTypes[] = $typePart;
                }
            }

            // If there were any parameters we're not converting to a
            // mime-type, we need to keep them.
            if ($newTypes) {
                $parameters['TYPE']->setParts($newTypes);
            } else {
                unset($parameters['TYPE']);
            }

        }

        $newProperty->setValue('data:' . $mimeType . ';base64,' . base64_encode($property->getValue()));

        return $newProperty;

    }

    /**
     * Converts a URI property to a BINARY property.
     *
     * In vCard 4.0 attachments are encoded as data: uri. Even though these may
     * be valid in vCard 3.0 as well, we should convert those to BINARY if
     * possible, to improve compatibility.
     *
     * @param Component\VCard $output
     * @param Property\Uri $property The input property.
     * @param $parameters List of parameters that will eventually be added to
     *                    the new property.
     * @return Property\Binary|null
     */
    protected function convertUriToBinary(Component\VCard $output, Property\Uri $property, array &$parameters) {

        $value = $property->getValue();

        // Only converting data: uris
        if (substr($value, 0, 5)!=='data:') {
            return;
        }

        $newProperty = $output->createProperty(
            $property->name,
            null, // no value
            [], // no parameters yet
            'BINARY'
        );

        $mimeType = substr($value, 5, strpos($value, ',')-5);
        if (strpos($mimeType, ';')) {
            $mimeType = substr($mimeType,0,strpos($mimeType, ';'));
            $newProperty->setValue(base64_decode(substr($value, strpos($value,',')+1)));
        } else {
            $newProperty->setValue(substr($value, strpos($value,',')+1));
        }
        unset($value);

        $newProperty['ENCODING'] = 'b';
        switch($mimeType) {

            case 'image/jpeg' :
                $newProperty['TYPE'] = 'JPEG';
                break;
            case 'image/png' :
                $newProperty['TYPE'] = 'PNG';
                break;
            case 'image/gif' :
                $newProperty['TYPE'] = 'GIF';
                break;

        }


        return $newProperty;

    }

    /**
     * Adds parameters to a new property for vCard 4.0
     *
     * @param Property $newProperty
     * @param array $parameters
     * @return void
     */
    protected function convertParameters40(Property $newProperty, array $parameters) {

        // Adding all parameters.
        foreach($parameters as $param) {

            // vCard 2.1 allowed parameters with no name
            if ($param->noName) $param->noName = false;

            switch($param->name) {

                // We need to see if there's any TYPE=PREF, because in vCard 4
                // that's now PREF=1.
                case 'TYPE' :
                    foreach($param->getParts() as $paramPart) {

                        if (strtoupper($paramPart)==='PREF') {
                            $newProperty->add('PREF','1');
                        } else {
                            $newProperty->add($param->name, $paramPart);
                        }

                    }
                    break;
                // These no longer exist in vCard 4
                case 'ENCODING' :
                case 'CHARSET' :
                    break;

                default :
                    $newProperty->add($param->name, $param->getParts());
                    break;

            }

        }

    }

    /**
     * Adds parameters to a new property for vCard 3.0
     *
     * @param Property $newProperty
     * @param array $parameters
     * @return void
     */
    protected function convertParameters30(Property $newProperty, array $parameters) {

        // Adding all parameters.
        foreach($parameters as $param) {

            // vCard 2.1 allowed parameters with no name
            if ($param->noName) $param->noName = false;

            switch($param->name) {

                case 'ENCODING' :
                    // This value only existed in vCard 2.1, and should be
                    // removed for anything else.
                    if (strtoupper($param->getValue())!=='QUOTED-PRINTABLE') {
                        $newProperty->add($param->name, $param->getParts());
                    }
                    break;

                /*
                 * Converting PREF=1 to TYPE=PREF.
                 *
                 * Any other PREF numbers we'll drop.
                 */
                case 'PREF' :
                    if ($param->getValue()=='1') {
                        $newProperty->add('TYPE','PREF');
                    }
                    break;

                default :
                    $newProperty->add($param->name, $param->getParts());
                    break;

            }

        }

    }
}
